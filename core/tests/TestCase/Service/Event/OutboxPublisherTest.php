<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Event;

use App\Service\Event\OutboxPublisher;
use App\Service\Tenant\TenantService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Test that on publishing the **tenant** of the calling context is recorded on
 * the event (B6: "carry the tenant along with the event"), or NULL without a context.
 */
class OutboxPublisherTest extends TestCase
{
    public function testPublishRecordsTenantFromContext(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->begin();
        try {
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => TenantService::DEFAULT_TENANT_ID]);
            $id = (new OutboxPublisher())->publish('zztest.tenant.event', ['x' => 1]);
            $row = $conn->execute('SELECT tenant_id FROM event_outbox WHERE id = :id', ['id' => $id])->fetch('assoc');
            $this->assertSame(TenantService::DEFAULT_TENANT_ID, (string)$row['tenant_id']);

            // Without a tenant context -> NULL (system-wide event).
            $conn->execute("SELECT set_config('app.current_tenant_id', '', true)");
            $id2 = (new OutboxPublisher())->publish('zztest.system.event', []);
            $row2 = $conn->execute('SELECT tenant_id FROM event_outbox WHERE id = :id', ['id' => $id2])->fetch('assoc');
            $this->assertNull($row2['tenant_id']);
        } finally {
            $conn->rollback();
        }
    }
}
