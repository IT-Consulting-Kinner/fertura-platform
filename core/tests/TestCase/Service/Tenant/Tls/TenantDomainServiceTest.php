<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Tenant\Tls;

use App\Service\Tenant\Tls\TenantDomainService;
use App\Service\Tenant\Tls\TlsCertException;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Custom-domain registration (E158): pending + verification token, operator
 * activation, host validation, tenant scoping.
 */
class TenantDomainServiceTest extends TestCase
{
    private const DEFAULT_TENANT = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
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
        // Dropping the domain cascades to its certificates (FK ON DELETE CASCADE).
        $this->conn()->execute("DELETE FROM tenant_domains WHERE host LIKE 'zz%'");
    }

    public function testCreateProducesPendingDomainWithToken(): void
    {
        $svc = new TenantDomainService();
        $d = $svc->create(self::DEFAULT_TENANT, 'zzkb.example.test');

        $this->assertSame('zzkb.example.test', $d['host']);
        $this->assertSame('pending', $d['status']);
        $this->assertStringStartsWith('fertura-domain-verify=', $d['verification_token']);

        $row = $svc->find($d['id']);
        $this->assertNotNull($row);
        $this->assertNull($row['verified_at']);
    }

    public function testMarkVerifiedActivates(): void
    {
        $svc = new TenantDomainService();
        $d = $svc->create(self::DEFAULT_TENANT, 'zzverify.example.test');

        $svc->markVerified($d['id']);

        $row = $svc->find($d['id']);
        $this->assertNotNull($row);
        $this->assertSame('active', $row['status']);
        $this->assertNotNull($row['verified_at']);
    }

    public function testInvalidHostIsRejected(): void
    {
        $svc = new TenantDomainService();
        $this->assertFalse($svc->isValidHost('not a host'));
        $this->assertFalse($svc->isValidHost('https://kb.example.test'));
        $this->assertFalse($svc->isValidHost('localhost'));
        $this->assertTrue($svc->isValidHost('kb.example.test'));

        $this->expectException(TlsCertException::class);
        $svc->create(self::DEFAULT_TENANT, 'not a host');
    }

    public function testListForTenant(): void
    {
        $svc = new TenantDomainService();
        $svc->create(self::DEFAULT_TENANT, 'zzone.example.test');
        $svc->create(self::DEFAULT_TENANT, 'zztwo.example.test');

        $hosts = array_map(fn(array $r): string => (string)$r['host'], $svc->listForTenant(self::DEFAULT_TENANT));
        $this->assertContains('zzone.example.test', $hosts);
        $this->assertContains('zztwo.example.test', $hosts);
    }
}
