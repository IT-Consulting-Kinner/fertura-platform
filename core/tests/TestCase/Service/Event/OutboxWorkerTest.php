<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Event;

use App\Service\Cache\CacheStore;
use App\Service\Event\OutboxWorker;
use App\Service\Settings\SettingsManager;
use App\Service\Tenant\TenantService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Test of tenant fairness in the outbox worker (pool model): round-robin claiming
 * prevents a single tenant from monopolizing the worker; a per-tenant cap
 * additionally limits the events per tenant per batch.
 */
class OutboxWorkerTest extends TestCase
{
    private function seed(string $tenant, int $count): void
    {
        $conn = ConnectionManager::get('default');
        for ($i = 0; $i < $count; $i++) {
            $conn->execute(
                "INSERT INTO event_outbox (contract_name, payload, max_attempts, tenant_id) "
                . "VALUES ('zztest.fair', '{}'::jsonb, 5, :t)",
                ['t' => $tenant],
            );
        }
    }

    private function claimed(string $tenant): int
    {
        return (int)ConnectionManager::get('default')->execute(
            "SELECT count(*) AS c FROM event_outbox WHERE contract_name = 'zztest.fair' "
            . "AND tenant_id = :t AND status <> 'pending'",
            ['t' => $tenant],
        )->fetch('assoc')['c'];
    }

    public function testRoundRobinClaimingIsFairAcrossTenants(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->begin();
        try {
            $a = TenantService::DEFAULT_TENANT_ID;
            $b = (new TenantService())->create('zztest-fair-b', 'B')['id'];
            $this->seed($a, 10);
            $this->seed($b, 10);

            $n = (new OutboxWorker())->processBatch(10);
            $this->assertSame(10, $n);
            // Without fairness all 10 of A would be claimed first; round-robin -> 5 each.
            $this->assertSame(5, $this->claimed($a), 'Mandant A bekommt seinen fairen Anteil');
            $this->assertSame(5, $this->claimed($b), 'Mandant B wird nicht ausgehungert');
        } finally {
            $conn->rollback();
        }
    }

    public function testPerTenantCapLimitsBatch(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->begin();
        try {
            $a = TenantService::DEFAULT_TENANT_ID;
            $b = (new TenantService())->create('zztest-fair-c', 'C')['id'];
            $this->seed($a, 5);
            $this->seed($b, 5);

            (new SettingsManager())->set('core', 'outbox.max_per_tenant_per_batch', 2);
            $n = (new OutboxWorker())->processBatch(10);
            $this->assertSame(4, $n, 'max 2 je Mandant -> 4 von 10');
            $this->assertSame(2, $this->claimed($a));
            $this->assertSame(2, $this->claimed($b));
        } finally {
            $conn->rollback();
            (new CacheStore('_app_settings_'))->clear();
        }
    }
}
