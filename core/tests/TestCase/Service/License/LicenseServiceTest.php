<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\License;

use App\Service\License\LicenseService;
use App\Service\Security\Signer;
use App\Service\Security\TrustStore;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use RuntimeException;

/**
 * Tests offline-first licensing (ch. 28.7, Decision 158): file validation
 * against the real trust chain (Ed25519), installation (upsert) and the
 * evaluate() status matrix — valid / grace / expired / needs_online /
 * revoked / missing, including online enforcement + recordOnlineCheck.
 */
class LicenseServiceTest extends TestCase
{
    private string $keyId = '';
    private string $secret = '';
    private string $moduleKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = bin2hex(random_bytes(5));
        $this->keyId = 'ztest-lic-root-' . $suffix;
        $this->moduleKey = 'zztest_licmod_' . $suffix;

        $pair = Signer::generateKeypair();
        $this->secret = $pair['secret'];
        (new TrustStore())->addAnchor($this->keyId, $pair['public'], 'root');
    }

    protected function tearDown(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute('DELETE FROM licenses WHERE module_key = :k', ['k' => $this->moduleKey]);
        $conn->execute('DELETE FROM trust_anchors WHERE key_id = :k', ['k' => $this->keyId]);
        $conn->execute('DELETE FROM revoked_keys WHERE key_id = :k', ['k' => $this->keyId]);
        parent::tearDown();
    }

    /** @param array<string,mixed> $payload */
    private function licenseJson(array $payload): string
    {
        $signature = (new Signer())->sign(LicenseService::canonical($payload), $this->secret);

        return (string)json_encode([
            'payload' => $payload,
            'signature' => $signature,
            'key_id' => $this->keyId,
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function payload(array $extra = []): array
    {
        return $extra + [
            'module_ref' => $this->moduleKey,
            'issuer' => 'Fertura Test',
            'valid_from' => null,
            'valid_to' => null,
        ];
    }

    public function testValidateFileHappyPathAndRejections(): void
    {
        $service = new LicenseService();

        // Valid.
        $ok = $service->validateFile($this->licenseJson($this->payload()));
        $this->assertTrue($ok['ok']);
        $this->assertSame($this->keyId, $ok['key_id']);

        // Structurally broken.
        $this->assertFalse($service->validateFile('{"nope":true}')['ok']);

        // Tampered payload -> signature invalid.
        $json = json_decode($this->licenseJson($this->payload()), true);
        $json['payload']['module_ref'] = 'evil_module';
        $bad = $service->validateFile((string)json_encode($json));
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsStringIgnoringCase('signatur', (string)$bad['reason']);

        // Unknown anchor.
        $json = json_decode($this->licenseJson($this->payload()), true);
        $json['key_id'] = 'ztest-unknown-anchor';
        $this->assertFalse($service->validateFile((string)json_encode($json))['ok']);
    }

    public function testValidateFileRejectsRevokedAndExpiredAnchor(): void
    {
        $service = new LicenseService();
        $json = $this->licenseJson($this->payload());

        // Anchor expired -> rejected (the E45 window applies to licenses too).
        ConnectionManager::get('default')->execute(
            "UPDATE trust_anchors SET valid_to = now() - interval '1 day' WHERE key_id = :k",
            ['k' => $this->keyId],
        );
        $expired = $service->validateFile($json);
        $this->assertFalse($expired['ok']);

        // Revoked key -> rejected (takes precedence over everything else).
        ConnectionManager::get('default')->execute(
            'UPDATE trust_anchors SET valid_to = NULL WHERE key_id = :k',
            ['k' => $this->keyId],
        );
        (new TrustStore())->revokeKey($this->keyId, 'Test');
        $revoked = $service->validateFile($json);
        $this->assertFalse($revoked['ok']);
        $this->assertStringContainsString('widerrufen', (string)$revoked['reason']);
    }

    public function testInstallRequiresModuleRefAndUpserts(): void
    {
        $service = new LicenseService();

        // Without module_ref -> abort.
        try {
            $service->install($this->licenseJson(['module_ref' => '', 'issuer' => 'x']));
            $this->fail('Erwartete RuntimeException (module_ref fehlt).');
        } catch (RuntimeException) {
            // expected
        }

        // Installation -> status valid (unlimited validity).
        $first = $service->install($this->licenseJson($this->payload()));
        $this->assertSame('valid', $first['status']);

        // Upsert: a second install with a future expiry replaces the first.
        $second = $service->install($this->licenseJson($this->payload([
            'valid_to' => date('c', time() + 30 * 86400),
        ])));
        $this->assertSame('valid', $second['status']);
        $count = (int)ConnectionManager::get('default')->execute(
            'SELECT count(*) AS c FROM licenses WHERE module_key = :k',
            ['k' => $this->moduleKey],
        )->fetch('assoc')['c'];
        $this->assertSame(1, $count); // one record per module (upsert)
    }

    public function testEvaluateStatusMatrix(): void
    {
        $service = new LicenseService();
        $conn = ConnectionManager::get('default');

        // missing: no license present.
        $this->assertSame('missing', $service->evaluate($this->moduleKey)['status']);

        $service->install($this->licenseJson($this->payload()));
        $this->assertSame('valid', $service->evaluate($this->moduleKey)['status']);
        $this->assertTrue($service->isValid($this->moduleKey));

        // Expired WITH a grace window -> grace (limited validity).
        $conn->execute(
            "UPDATE licenses SET valid_to = now() - interval '2 days', grace_window_days = 10 WHERE module_key = :k",
            ['k' => $this->moduleKey],
        );
        $grace = $service->evaluate($this->moduleKey);
        $this->assertSame('grace', $grace['status']);
        $this->assertTrue($service->isValid($this->moduleKey)); // activation remains allowed

        // Expired BEYOND the grace window -> expired.
        $conn->execute(
            "UPDATE licenses SET valid_to = now() - interval '20 days', grace_window_days = 10 WHERE module_key = :k",
            ['k' => $this->moduleKey],
        );
        $this->assertSame('expired', $service->evaluate($this->moduleKey)['status']);
        $this->assertFalse($service->isValid($this->moduleKey));

        // Revocation of the signing key -> revoked (independent of the time window).
        (new TrustStore())->revokeKey($this->keyId, 'Test');
        $this->assertSame('revoked', $service->evaluate($this->moduleKey)['status']);
    }

    public function testOnlineEnforcementNeedsFreshCheck(): void
    {
        $service = new LicenseService();
        $conn = ConnectionManager::get('default');
        $service->install($this->licenseJson($this->payload(['online_enforcement' => true])));

        // Never confirmed online -> needs_online.
        $this->assertSame('needs_online', $service->evaluate($this->moduleKey)['status']);

        // Fresh confirmation -> valid.
        $service->recordOnlineCheck($this->moduleKey);
        $this->assertSame('valid', $service->evaluate($this->moduleKey)['status']);

        // Confirmation stale (older than license.online_max_age_days=7) -> needs_online.
        $conn->execute(
            "UPDATE licenses SET last_online_check = now() - interval '30 days' WHERE module_key = :k",
            ['k' => $this->moduleKey],
        );
        $this->assertSame('needs_online', $service->evaluate($this->moduleKey)['status']);

        // Overdue online confirmation WITHIN the grace window -> grace.
        $conn->execute(
            "UPDATE licenses SET valid_to = now() - interval '1 day', grace_window_days = 10 WHERE module_key = :k",
            ['k' => $this->moduleKey],
        );
        $graceOnline = $service->evaluate($this->moduleKey);
        $this->assertSame('grace', $graceOnline['status']);
    }
}
