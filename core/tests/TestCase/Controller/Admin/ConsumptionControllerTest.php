<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use App\Service\Consumption\TenantConsumptionService;
use App\Service\Storage\StorageManager;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Throwable;

/**
 * Integration test for the tenant consumption dashboard (Inc 7d): a tenant admin
 * sees what THEIR OWN tenant consumes (enabled modules + storage + DB footprint).
 * The area is tenant-scoped and every figure is RLS-scoped to core.current_tenant(),
 * so one tenant's usage never bleeds into another's view — proven both through the
 * controller and directly against {@see TenantConsumptionService}.
 */
class ConsumptionControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private string $userId = '';
    private string $tenantId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        // The areas the test grants (consumption is the gated one; tenant_modules lets
        // a user hold an admin area WITHOUT consumption, for the gate test).
        $conn->execute(
            "INSERT INTO admin_areas (area_key, label, sort_order) VALUES ('consumption', 'Verbrauch', 35) "
            . 'ON CONFLICT (area_key) DO NOTHING',
        );
        $conn->execute(
            "INSERT INTO admin_areas (area_key, label, sort_order) VALUES ('tenant_modules', 'Module', 25) "
            . 'ON CONFLICT (area_key) DO NOTHING',
        );
        // A demo module the tenant can enable -> it appears as a consumed "function".
        $conn->execute(
            'INSERT INTO modules (module_key, name, version, type, core_compatibility, status, manifest) '
            . "VALUES ('zzcons', 'ZZ Consumption Demo', '1.0.0', 'extension', '^1.0.0', 'active', '{}'::jsonb) "
            . 'ON CONFLICT (module_key) DO NOTHING',
        );

        $this->tenantId = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zzcons-' || substr(md5(random()::text), 1, 8), 'ZZ Cons') RETURNING id",
        )->fetch('assoc')['id'];
        $this->userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status, tenant_id) VALUES (:u, :e, 'active', :t) RETURNING id",
            [
                'u' => 'zzcons_' . bin2hex(random_bytes(3)),
                'e' => 'cons_' . bin2hex(random_bytes(3)) . '@zzcons.local',
                't' => $this->tenantId,
            ],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $this->userId, 'a' => 'consumption'],
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        $storage = new StorageManager();
        foreach ($conn->execute("SELECT id FROM tenants WHERE key LIKE 'zzcons-%'")->fetchAll('assoc') as $t) {
            try {
                $storage->deleteDirectory('tenant/' . (string)$t['id']);
            } catch (Throwable) {
                // No file subtree for this tenant.
            }
        }
        $conn->execute(
            'DELETE FROM user_admin_areas WHERE user_id IN '
            . "(SELECT id FROM users WHERE email LIKE '%@zzcons.local')",
        );
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzcons.local'");
        $conn->execute("DELETE FROM automation_rules WHERE name LIKE 'zzcons%'");
        // tenant_modules cascades with the tenant; the global demo module is shared, so
        // drop its grants then the module row itself.
        $conn->execute("DELETE FROM tenant_modules WHERE module_key = 'zzcons'");
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zzcons-%'");
        $conn->execute("DELETE FROM modules WHERE module_key = 'zzcons'");
    }

    private function login(string $userId): void
    {
        $this->session(['Auth' => ['id' => $userId, 'username' => 'zzcons', 'email' => 'c@zzcons.local']]);
    }

    /** Enables the demo module + seeds a core row so the dashboard has something to show. */
    private function seedUsage(string $tenantId): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute(
            'INSERT INTO tenant_modules (tenant_id, module_key, enabled) VALUES (:t, :k, true) '
            . 'ON CONFLICT (tenant_id, module_key) DO UPDATE SET enabled = true',
            ['t' => $tenantId, 'k' => 'zzcons'],
        );
        $conn->execute(
            'INSERT INTO automation_rules (name, event, condition, actions, active, tenant_id) '
            . "VALUES ('zzcons_rule', 'zzcons.evt', '{}'::jsonb, '[]'::jsonb, true, :t)",
            ['t' => $tenantId],
        );
    }

    public function testDashboardRendersTenantUsage(): void
    {
        $this->seedUsage($this->tenantId);
        // A stored backup archive (50 KB) drives the "backup archives" KPI.
        ConnectionManager::get('default')->execute(
            "INSERT INTO tenant_backups (tenant_id, filename, storage_path, bytes) VALUES (:t, 'zz.zip', '/tmp/zz.zip', 51200)",
            ['t' => $this->tenantId],
        );

        $this->login($this->userId);
        $this->get('/admin/consumption');
        $this->assertResponseOk();
        // Enabled module shown as a consumed function.
        $this->assertResponseContains('ZZ Consumption Demo');
        $this->assertResponseContains('zzcons');
        // A tenant-scoped core table appears in the footprint.
        $this->assertResponseContains('automation_rules');
        // The backup archive size is summed (51200 bytes -> "50 KB").
        $this->assertResponseContains('50 KB');
    }

    public function testGateDeniesUserWithoutConsumptionArea(): void
    {
        // A user holding a different admin area (so they pass the "any admin" check)
        // but NOT 'consumption' must be refused — the grant alone gates the page.
        $conn = ConnectionManager::get('default');
        $other = (string)$conn->execute(
            "INSERT INTO users (username, email, status, tenant_id) VALUES (:u, :e, 'active', :t) RETURNING id",
            [
                'u' => 'zzcons_o_' . bin2hex(random_bytes(3)),
                'e' => 'cons_o_' . bin2hex(random_bytes(3)) . '@zzcons.local',
                't' => $this->tenantId,
            ],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $other, 'a' => 'tenant_modules'],
        );

        $this->login($other);
        $this->get('/admin/consumption');
        $this->assertResponseCode(403);
    }

    public function testSummaryIsTenantScoped(): void
    {
        // Tenant A consumes a module + a rule + a file; tenant B is empty. Under each
        // tenant's RLS context the summary reflects only that tenant.
        $conn = ConnectionManager::get('default');
        $this->seedUsage($this->tenantId);
        (new StorageManager())->write('tenant/' . $this->tenantId . '/docs/note.txt', 'HELLO-WORLD'); // 11 bytes

        $tidB = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zzcons-' || substr(md5(random()::text), 1, 8), 'ZZ Cons B') RETURNING id",
        )->fetch('assoc')['id'];

        $svc = new TenantConsumptionService();

        // --- Tenant A ---
        $conn->execute("SELECT set_config('app.current_tenant_id', :t, false)", ['t' => $this->tenantId]);
        $a = $svc->summary();
        $this->assertNotNull($a);
        $this->assertSame(1, $a['module_count'], 'A has one enabled module');
        $this->assertSame('zzcons', $a['modules'][0]['module_key']);
        $this->assertGreaterThanOrEqual(11, $a['storage']['file_bytes'], "A's file bytes are summed");
        $this->assertSame(1, $a['storage']['file_count']);
        $tablesA = array_column($a['footprint']['tables'], 'rows', 'key');
        $this->assertArrayHasKey('automation_rules', $tablesA, "A's rule is counted");
        $this->assertGreaterThanOrEqual(1, $tablesA['automation_rules']);

        // --- Tenant B (empty) ---
        $conn->execute("SELECT set_config('app.current_tenant_id', :t, false)", ['t' => $tidB]);
        $b = $svc->summary();
        $this->assertNotNull($b);
        $this->assertSame(0, $b['module_count'], 'B has no modules — A never leaks in');
        $this->assertSame(0, $b['storage']['file_count'], 'B has no files');
        $tablesB = array_column($b['footprint']['tables'], 'rows', 'key');
        $this->assertArrayNotHasKey('automation_rules', $tablesB, "A's rule never appears for B");

        // --- No context -> fail-closed null ---
        $conn->execute("SELECT set_config('app.current_tenant_id', '', false)");
        $this->assertNull($svc->summary(), 'no tenant context yields no summary');
    }
}
