<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Tenant\Tls;

use App\Service\Tenant\Tls\TlsCertException;
use App\Service\Tenant\Tls\TlsCertService;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;

/**
 * TLS certificate validation and encrypted storage (E158): host coverage,
 * validity window, key match, private-key round-trip through SecretCipher.
 */
class TlsCertServiceTest extends TestCase
{
    private const DEFAULT_TENANT = '00000000-0000-0000-0000-000000000001';
    private const HOST = 'zzkbcert.example.test';

    private string $domainId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->domainId = (string)$this->conn()->execute(
            'INSERT INTO tenant_domains (tenant_id, host, verification_token) '
            . "VALUES (:t, :h, 'zz-token') RETURNING id",
            ['t' => self::DEFAULT_TENANT, 'h' => self::HOST],
        )->fetch('assoc')['id'];
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $c */
        $c = ConnectionManager::get('default');

        return $c;
    }

    private function cleanup(): void
    {
        $this->conn()->execute("DELETE FROM tenant_domains WHERE host LIKE 'zz%'");
    }

    /**
     * Generates a self-signed certificate (with subjectAltName) and its key.
     *
     * @param list<string> $sans
     * @return array{cert: string, key: string}
     */
    private function makeCert(array $sans, int $days): array
    {
        $cnfPath = sys_get_temp_dir() . '/zztls_' . bin2hex(random_bytes(4)) . '.cnf';
        $altLines = '';
        $i = 1;
        foreach ($sans as $h) {
            $altLines .= "DNS.$i = $h\n";
            $i++;
        }
        $conf = "[req]\ndistinguished_name = dn\nreq_extensions = v3_req\nx509_extensions = v3_req\nprompt = no\n"
            . "[dn]\nCN = {$sans[0]}\n[v3_req]\nsubjectAltName = @alt\n[alt]\n" . $altLines;
        file_put_contents($cnfPath, $conf);

        $cfg = ['config' => $cnfPath, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $pkey = openssl_pkey_new($cfg);
        if (!$pkey instanceof OpenSSLAsymmetricKey) {
            $this->fail('openssl_pkey_new failed');
        }
        $csr = openssl_csr_new(['commonName' => $sans[0]], $pkey, $cfg);
        if (!$csr instanceof OpenSSLCertificateSigningRequest) {
            $this->fail('openssl_csr_new failed');
        }
        $x509 = openssl_csr_sign($csr, null, $pkey, $days, $cfg);
        if (!$x509 instanceof OpenSSLCertificate) {
            $this->fail('openssl_csr_sign failed');
        }
        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($pkey, $keyPem, null, $cfg);
        if (is_file($cnfPath)) {
            unlink($cnfPath);
        }

        return ['cert' => (string)$certPem, 'key' => (string)$keyPem];
    }

    public function testValidateAcceptsMatchingBundle(): void
    {
        $c = $this->makeCert([self::HOST], 90);
        $r = (new TlsCertService())->validateBundle(self::HOST, $c['cert'], null, $c['key']);

        $this->assertTrue($r['ok'], implode(' ', $r['errors']));
        $this->assertContains(self::HOST, $r['meta']['sans']);
        $this->assertNotNull($r['meta']['notAfter']);
        $this->assertGreaterThan(time(), strtotime((string)$r['meta']['notAfter']));
        $this->assertNotNull($r['meta']['fingerprint']);
    }

    public function testValidateRejectsWrongHost(): void
    {
        $c = $this->makeCert([self::HOST], 90);
        $r = (new TlsCertService())->validateBundle('other.example.test', $c['cert'], null, $c['key']);

        $this->assertFalse($r['ok']);
        $this->assertTrue(
            (bool)array_filter($r['errors'], fn(string $e): bool => str_contains($e, 'deckt die Domain')),
        );
    }

    public function testValidateRejectsKeyMismatch(): void
    {
        $c = $this->makeCert([self::HOST], 90);
        $other = $this->makeCert([self::HOST], 90);
        $r = (new TlsCertService())->validateBundle(self::HOST, $c['cert'], null, $other['key']);

        $this->assertFalse($r['ok']);
        $this->assertTrue((bool)array_filter($r['errors'], fn(string $e): bool => str_contains($e, 'passt nicht')));
    }

    public function testValidateRejectsExpired(): void
    {
        // openssl cannot backdate a cert, so evaluate a valid cert as if "now" is
        // past its expiry — this deterministically exercises the notAfter branch.
        $c = $this->makeCert([self::HOST], 90);
        $svc = new TlsCertService();
        $meta = $svc->validateBundle(self::HOST, $c['cert'], null, $c['key'])['meta'];
        $notAfter = (int)strtotime((string)$meta['notAfter']);

        $r = $svc->validateBundle(self::HOST, $c['cert'], null, $c['key'], $notAfter + 86400);

        $this->assertFalse($r['ok']);
        $this->assertTrue((bool)array_filter($r['errors'], fn(string $e): bool => str_contains($e, 'abgelaufen')));
    }

    public function testStoreEncryptsKeyAndExtractsMetadata(): void
    {
        $c = $this->makeCert([self::HOST], 90);
        $svc = new TlsCertService();

        $certId = $svc->store($this->domainId, self::HOST, $c['cert'], null, $c['key'], null);

        // Private key round-trips through SecretCipher (stored encrypted, not plaintext).
        $this->assertSame(trim($c['key']), trim($svc->privateKeyPem($certId)));
        $stored = (string)$this->conn()->execute(
            'SELECT key_cipher FROM tenant_domain_certs WHERE id = :id',
            ['id' => $certId],
        )->fetch('assoc')['key_cipher'];
        $this->assertStringNotContainsString('PRIVATE KEY', $stored);

        $list = $svc->listForDomain($this->domainId);
        $this->assertCount(1, $list);
        $this->assertSame('pending_deploy', $list[0]['status']);
        $this->assertNotNull($list[0]['not_after']);
        $this->assertNotNull($list[0]['fingerprint_sha256']);
    }

    public function testStoreRejectsInvalidBundle(): void
    {
        $c = $this->makeCert([self::HOST], 90);
        $this->expectException(TlsCertException::class);
        (new TlsCertService())->store($this->domainId, 'wrong.example.test', $c['cert'], null, $c['key'], null);
    }
}
