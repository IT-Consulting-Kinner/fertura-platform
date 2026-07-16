<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Audit;

use App\Audit\AuditLogger;
use App\Service\Audit\AuditExportService;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Cake\TestSuite\TestCase;

/**
 * Tests the audit export stream (item 3b): keyset streaming across the time
 * range, correct filters (action/entity_type/from/to) and parsing of the jsonb
 * value snapshots. Additionally (item 3a): the AuditLogger mirrors every event
 * to the `audit` log channel with a structured `audit` field.
 */
class AuditExportServiceTest extends TestCase
{
    private const ROLE = 'fertura_rls_audtest';

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

    private function cleanup(): void
    {
        // audit_log is append-only (trigger); test cleanup goes through the
        // documented bypass (only within a transaction via SET LOCAL).
        $conn = ConnectionManager::get('default');
        $conn->execute("SELECT set_config('app.current_tenant_id', '', false)");
        $conn->begin();
        $conn->execute("SET LOCAL app.allow_audit_mutation = 'on'");
        $conn->execute("DELETE FROM audit_log WHERE action LIKE 'zztest.%'");
        $conn->commit();
        // The audit rows referencing the test tenants are gone (above), so the FK is free.
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zzaud_%'");
    }

    private function seed(string $action, ?array $newValue = null): void
    {
        (new AuditLogger())->log($action, 'zztest_entity', '019eb000-0000-7000-8000-000000000001', [
            'newValue' => $newValue,
            'component' => 'core',
        ]);
    }

    public function testStreamReturnsMatchingRowsWithFilters(): void
    {
        $this->seed('zztest.alpha', ['k' => 'v1']);
        $this->seed('zztest.beta');
        $this->seed('zztest.alpha', ['k' => 'v2']);

        $svc = new AuditExportService();
        $alpha = iterator_to_array($svc->stream(['action' => 'zztest.alpha']));
        $this->assertCount(2, $alpha);
        // jsonb is delivered as a parsed structure (NDJSON-capable).
        $this->assertSame(['k' => 'v1'], $alpha[0]['new_value']);

        // The entity_type filter matches all three test events.
        $all = iterator_to_array($svc->stream(['entity_type' => 'zztest_entity']));
        $this->assertCount(3, $all);

        // with_values=false omits the snapshots (SIEM pull mode).
        $lean = iterator_to_array($svc->stream(['action' => 'zztest.alpha', 'with_values' => false]));
        $this->assertArrayNotHasKey('new_value', $lean[0]);
    }

    public function testStreamIsKeysetOrderedAcrossBatches(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seed('zztest.seq');
        }
        // Setting the batch size via reflection would be invasive; here we check
        // the stable (created_at,id) ordering across the generator.
        $rows = iterator_to_array((new AuditExportService())->stream(['action' => 'zztest.seq']));
        $this->assertCount(5, $rows);
        $prev = null;
        foreach ($rows as $r) {
            $key = $r['created_at'] . '|' . $r['id'];
            if ($prev !== null) {
                $this->assertGreaterThan($prev, $key, 'streng aufsteigende Keyset-Ordnung');
            }
            $prev = $key;
        }
    }

    public function testFromToWindowFilters(): void
    {
        $this->seed('zztest.window');
        // Future "from" -> no matches; past "to" -> no matches.
        $future = iterator_to_array((new AuditExportService())->stream([
            'action' => 'zztest.window', 'from' => date('c', time() + 86400),
        ]));
        $this->assertCount(0, $future);
        $past = iterator_to_array((new AuditExportService())->stream([
            'action' => 'zztest.window', 'to' => date('c', time() - 86400),
        ]));
        $this->assertCount(0, $past);
    }

    public function testExportReappliesTenantContextUnderRls(): void
    {
        // Peer-review F11/F15: the export streams AFTER the request tx commits, when
        // core.current_tenant() is NULL. Under a NOBYPASSRLS role this means the
        // audit_log RLS policy (tenant_id = core.current_tenant()) matches nothing —
        // so the export was silently empty. The fix: the controller captures the
        // tenant and stream($filters, $tenantId) re-applies it. This test reproduces
        // the post-commit condition (role + no ambient context) and proves the fix.
        $a = $this->makeTenant('zzaud_a');
        $b = $this->makeTenant('zzaud_b');
        $this->seedForTenant('zztest.scoped', $a);
        $this->seedForTenant('zztest.scoped', $b);
        $this->ensureRole();

        // Without a re-applied tenant (the bug): RLS sees no context -> empty.
        $this->assertSame(0, $this->exportCountAsRole('zztest.scoped', null), 'no tenant context -> fail-closed empty');
        // With the captured tenant re-applied (the fix): exactly that tenant's row.
        $this->assertSame(1, $this->exportCountAsRole('zztest.scoped', $a), 'tenant A sees only its own audit row');
        $this->assertSame(1, $this->exportCountAsRole('zztest.scoped', $b), 'tenant B sees only its own audit row');
    }

    public function testOperatorGlobalIncludesNullTenantEventsButNotOtherTenants(): void
    {
        // A2: an operator reads own-tenant + operator-global (tenant_id IS NULL)
        // platform events, but never another tenant's. The operator path
        // (operatorGlobal = true) scopes explicitly in SQL over the privileged
        // connection — proven here by seeding one row per bucket.
        $a = $this->makeTenant('zzaud_opa');
        $b = $this->makeTenant('zzaud_opb');
        $this->seedForTenant('zztest.opscope', $a); // own-tenant event
        $this->seedForTenant('zztest.opscope', $b); // OTHER tenant — must stay hidden
        $this->seedGlobal('zztest.opscope');        // operator-global (tenant_id NULL)

        $rows = iterator_to_array(
            (new AuditExportService())->stream(['action' => 'zztest.opscope'], $a, true),
        );

        // Own-tenant row + the global row (2), but never tenant B's (would be 3).
        $this->assertCount(2, $rows, 'operator sees own tenant + global, never another tenant');
    }

    private function makeTenant(string $key): string
    {
        return (string)ConnectionManager::get('default')->execute(
            "INSERT INTO tenants (key, name) VALUES (:k || '_' || substr(md5(random()::text), 1, 6), :n) RETURNING id",
            ['k' => $key, 'n' => 'ZZ Audit'],
        )->fetch('assoc')['id'];
    }

    private function seedForTenant(string $action, string $tenantId): void
    {
        // AuditLogger does not set tenant_id explicitly — it defaults to
        // core.current_tenant(); set that context for the duration of the insert.
        $conn = ConnectionManager::get('default');
        $conn->execute("SELECT set_config('app.current_tenant_id', :t, false)", ['t' => $tenantId]);
        (new AuditLogger())->log($action, 'zztest_entity', '019eb000-0000-7000-8000-0000000000aa', ['component' => 'core']);
        $conn->execute("SELECT set_config('app.current_tenant_id', '', false)");
    }

    private function seedGlobal(string $action): void
    {
        // Operator-global / system event: cleared context -> tenant_id defaults to NULL.
        $conn = ConnectionManager::get('default');
        $conn->execute("SELECT set_config('app.current_tenant_id', '', false)");
        (new AuditLogger())->log($action, 'zztest_entity', '019eb000-0000-7000-8000-0000000000bb', ['component' => 'core']);
    }

    private function ensureRole(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "DO $$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='" . self::ROLE . "') THEN "
            . 'CREATE ROLE ' . self::ROLE . ' NOLOGIN NOBYPASSRLS; END IF; END $$;',
        );
        $conn->execute('GRANT USAGE ON SCHEMA core TO ' . self::ROLE);
        $conn->execute('GRANT SELECT ON core.audit_log TO ' . self::ROLE);
    }

    private function exportCountAsRole(string $action, ?string $tenantId): int
    {
        $conn = ConnectionManager::get('default');
        // Clean ambient context (session) so the null case truly has no tenant.
        $conn->execute("SELECT set_config('app.current_tenant_id', '', false)");
        $conn->begin();
        try {
            $conn->execute("SELECT set_config('app.bypass_rls', 'false', true)");
            $conn->execute('SET LOCAL ROLE ' . self::ROLE);
            // The export itself re-applies $tenantId (the fix); we set nothing here.
            return count(iterator_to_array((new AuditExportService())->stream(['action' => $action], $tenantId)));
        } finally {
            $conn->rollback();
        }
    }

    public function testAuditLoggerEmitsStructuredSiemLine(): void
    {
        $captured = [];
        Log::setConfig('zztest_audit_capture', [
            'engine' => 'Array',
            'scopes' => ['audit'],
            'levels' => ['info'],
        ]);
        try {
            $this->seed('zztest.siem', ['x' => 1]);
            /** @var \Cake\Log\Engine\ArrayLog $engine */
            $engine = Log::engine('zztest_audit_capture');
            $captured = $engine->read();
        } finally {
            Log::drop('zztest_audit_capture');
        }

        $this->assertNotEmpty($captured, 'Audit-Ereignis muss auf den audit-Log-Kanal gespiegelt werden');
        $line = implode("\n", $captured);
        $this->assertStringContainsString('audit.zztest.siem', $line);
        // Value snapshots are NOT in the stream (PII-light, E16).
        $this->assertStringNotContainsString('"x"', $line);
    }
}
