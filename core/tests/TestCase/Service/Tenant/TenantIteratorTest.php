<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Tenant;

use App\Service\Tenant\TenantIterator;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Worker-side tenant iteration (E163): runs a callback per active tenant with
 * that tenant's RLS context set; skips inactive tenants.
 */
class TenantIteratorTest extends TestCase
{
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
        $this->conn()->execute("DELETE FROM tenants WHERE key LIKE 'zzti_%'");
    }

    private function makeTenant(string $key, bool $active): string
    {
        return (string)$this->conn()->execute(
            'INSERT INTO tenants (key, name, active) VALUES (:k, :n, :a) RETURNING id',
            ['k' => $key, 'n' => 'ZZ ' . $key, 'a' => $active ? 'true' : 'false'],
        )->fetch('assoc')['id'];
    }

    public function testRunsPerActiveTenantWithContextSetAndSkipsInactive(): void
    {
        $a = $this->makeTenant('zzti_a', true);
        $b = $this->makeTenant('zzti_b', true);
        $inactive = $this->makeTenant('zzti_c', false);

        $seen = [];
        (new TenantIterator())->forEachActiveTenant(function (string $tid) use (&$seen): void {
            // The tenant RLS context must be set to exactly this tenant inside the run.
            $ctx = (string)$this->conn()
                ->execute("SELECT current_setting('app.current_tenant_id', true) AS t")
                ->fetch('assoc')['t'];
            $seen[$tid] = $ctx;
        });

        $this->assertArrayHasKey($a, $seen);
        $this->assertArrayHasKey($b, $seen);
        $this->assertArrayNotHasKey($inactive, $seen, 'inactive tenant must be skipped');
        $this->assertSame($a, $seen[$a], 'context must equal the iterated tenant');
        $this->assertSame($b, $seen[$b]);
    }

    public function testActiveTenantIdsExcludesInactive(): void
    {
        $a = $this->makeTenant('zzti_x', true);
        $inactive = $this->makeTenant('zzti_y', false);

        $ids = (new TenantIterator())->activeTenantIds();

        $this->assertContains($a, $ids);
        $this->assertNotContains($inactive, $ids);
    }
}
