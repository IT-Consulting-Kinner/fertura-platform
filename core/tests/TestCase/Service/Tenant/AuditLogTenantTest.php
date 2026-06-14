<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Tenant;

use App\Service\Permission\RlsContext;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * `core.audit_log` captures the current tenant on write (E165, Decision 185):
 * an entry written inside a tenant's RLS context is attributed to that tenant,
 * and a system / pre-auth entry (no context) gets NULL.
 *
 * Note: audit_log is append-only (immutable trigger), so the inserted rows
 * cannot be cleaned up — the tests use fixed action markers and read the most
 * recent row. The tenant fixture has no FK from audit_log, so it can be removed
 * while the (now orphaned) audit row stays as immutable history.
 */
class AuditLogTenantTest extends TestCase
{
    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $c */
        $c = ConnectionManager::get('default');

        return $c;
    }

    protected function tearDown(): void
    {
        $this->conn()->execute("DELETE FROM tenants WHERE key LIKE 'zzaud_%'");
        parent::tearDown();
    }

    public function testAuditInsertCapturesCurrentTenant(): void
    {
        $conn = $this->conn();
        $tenantId = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zzaud_cap', 'ZZ Audit') RETURNING id",
        )->fetch('assoc')['id'];

        $captured = null;
        $conn->transactional(function () use ($conn, $tenantId, &$captured): bool {
            (new RlsContext())->applyLocal($conn, null, [], false, $tenantId);
            $conn->execute(
                "INSERT INTO audit_log (action, entity_type) VALUES ('zztest.audit.capture', 'zztest')",
            );
            $captured = (string)$conn->execute(
                "SELECT tenant_id FROM audit_log WHERE action = 'zztest.audit.capture' "
                . 'ORDER BY created_at DESC LIMIT 1',
            )->fetch('assoc')['tenant_id'];

            return true;
        });

        $this->assertSame($tenantId, $captured, 'audit_log.tenant_id must capture the current tenant context');
    }

    public function testAuditInsertWithoutTenantContextIsNull(): void
    {
        $conn = $this->conn();

        $captured = 'sentinel';
        $conn->transactional(function () use ($conn, &$captured): bool {
            // Explicitly clear any tenant context (system / pre-auth event).
            (new RlsContext())->applyLocal($conn, null, [], false, null);
            $conn->execute(
                "INSERT INTO audit_log (action, entity_type) VALUES ('zztest.audit.systemnull', 'zztest')",
            );
            $captured = $conn->execute(
                "SELECT tenant_id FROM audit_log WHERE action = 'zztest.audit.systemnull' "
                . 'ORDER BY created_at DESC LIMIT 1',
            )->fetch('assoc')['tenant_id'];

            return true;
        });

        $this->assertNull($captured, 'system / pre-auth audit events have NULL tenant_id');
    }
}
