<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\System;

use App\Service\System\TenantProvisionHandler;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * The tenant-provision critical action (Phase 6 Increment 2): execute() creates the
 * tenant, verify() asserts it exists + is active, rollback() cleanly deletes the
 * fresh tenant. The stateful handler carries the created id across the three calls
 * (one instance per worker tick).
 */
class TenantProvisionHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        ConnectionManager::get('default')->execute("DELETE FROM tenants WHERE key LIKE 'zztest-prov-%'");
    }

    public function testExecuteCreatesVerifyOkRollbackDeletes(): void
    {
        $handler = new TenantProvisionHandler();
        $key = 'zztest-prov-' . bin2hex(random_bytes(3));

        $handler->execute(['key' => $key, 'name' => 'Prov Test']);
        $this->assertTrue($handler->verify([])['ok']); // exists + active

        $count = (int)ConnectionManager::get('default')->execute(
            'SELECT count(*) AS c FROM tenants WHERE key = :k',
            ['k' => $key],
        )->fetch('assoc')['c'];
        $this->assertSame(1, $count);

        // Action-own rollback removes the fresh tenant, and verify then fails.
        $handler->rollback([]);
        $this->assertFalse($handler->verify([])['ok']);
        $gone = (int)ConnectionManager::get('default')->execute(
            'SELECT count(*) AS c FROM tenants WHERE key = :k',
            ['k' => $key],
        )->fetch('assoc')['c'];
        $this->assertSame(0, $gone);
    }

    public function testVerifyFailsForUnknownTenant(): void
    {
        $verdict = (new TenantProvisionHandler())->verify(['tenant_id' => '019eaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa']);
        $this->assertFalse($verdict['ok']);
    }

    public function testVerifyFailsWithoutTenantId(): void
    {
        $this->assertFalse((new TenantProvisionHandler())->verify([])['ok']);
    }
}
