<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use App\Service\Consumption\OperatorConsumptionService;
use App\Service\Tenant\TenantService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integration test for the operator billing view (Inc 7e): a Betreiber-Admin sees
 * per-tenant consumption across all active tenants. The page is operator-gated (a
 * tenant admin is refused even with the core_config grant), and each tenant's figures
 * are read under that tenant's own RLS context — so they match the tenant's own
 * dashboard (Inc 7d) and never bleed across tenants.
 */
class OperatorConsumptionControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private string $operatorId = '';
    private string $tenantA = '';
    private string $tenantB = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "INSERT INTO admin_areas (area_key, label, sort_order) VALUES ('core_config', 'Konfiguration', 50) "
            . 'ON CONFLICT (area_key) DO NOTHING',
        );
        // A demo module enabled for tenant A -> A reports one consumed function.
        $conn->execute(
            'INSERT INTO modules (module_key, name, version, type, core_compatibility, status, manifest) '
            . "VALUES ('zzopcons', 'ZZ OpCons Demo', '1.0.0', 'extension', '^1.0.0', 'active', '{}'::jsonb) "
            . 'ON CONFLICT (module_key) DO NOTHING',
        );

        // Operator admin = a user in the operator (default) tenant, holding core_config.
        $this->operatorId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zzopc_op_' . bin2hex(random_bytes(3)), 'e' => 'op_' . bin2hex(random_bytes(3)) . '@zzopc.local'],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $this->operatorId, 'a' => 'core_config'],
        );

        // Two active tenants: A consumes a module + a rule, B is empty.
        $this->tenantA = $this->makeTenant('ZZ OpC A');
        $this->tenantB = $this->makeTenant('ZZ OpC B');
        $conn->execute(
            'INSERT INTO tenant_modules (tenant_id, module_key, enabled) VALUES (:t, :k, true) '
            . 'ON CONFLICT (tenant_id, module_key) DO UPDATE SET enabled = true',
            ['t' => $this->tenantA, 'k' => 'zzopcons'],
        );
        $conn->execute(
            'INSERT INTO automation_rules (name, event, condition, actions, active, tenant_id) '
            . "VALUES ('zzopc_rule', 'zzopc.evt', '{}'::jsonb, '[]'::jsonb, true, :t)",
            ['t' => $this->tenantA],
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function makeTenant(string $name): string
    {
        return (string)ConnectionManager::get('default')->execute(
            "INSERT INTO tenants (key, name, active) VALUES ('zzopc-' || substr(md5(random()::text), 1, 8), :n, true) RETURNING id",
            ['n' => $name],
        )->fetch('assoc')['id'];
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute(
            'DELETE FROM user_admin_areas WHERE user_id IN '
            . "(SELECT id FROM users WHERE email LIKE '%@zzopc.local')",
        );
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzopc.local'");
        $conn->execute("DELETE FROM automation_rules WHERE name LIKE 'zzopc%'");
        $conn->execute("DELETE FROM tenant_modules WHERE module_key = 'zzopcons'");
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zzopc-%'");
        $conn->execute("DELETE FROM modules WHERE module_key = 'zzopcons'");
    }

    private function login(string $userId): void
    {
        $this->session(['Auth' => ['id' => $userId, 'username' => 'zzopc', 'email' => 'o@zzopc.local']]);
    }

    public function testIndexListsTenantsForOperator(): void
    {
        $this->login($this->operatorId);
        $this->get('/admin/operator-consumption');
        $this->assertResponseOk();
        // Both active test tenants appear in the billing table.
        $this->assertResponseContains('ZZ OpC A');
        $this->assertResponseContains('ZZ OpC B');
        $this->assertResponseContains($this->tenantA);
    }

    public function testGateDeniesNonOperatorTenantAdmin(): void
    {
        // A tenant admin (user in a NON-default tenant) holding core_config is still
        // refused — core_config is an operator area, so the operator-tenant gate applies.
        $conn = ConnectionManager::get('default');
        $tenantUser = (string)$conn->execute(
            "INSERT INTO users (username, email, status, tenant_id) VALUES (:u, :e, 'active', :t) RETURNING id",
            [
                'u' => 'zzopc_t_' . bin2hex(random_bytes(3)),
                'e' => 't_' . bin2hex(random_bytes(3)) . '@zzopc.local',
                't' => $this->tenantA,
            ],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $tenantUser, 'a' => 'core_config'],
        );

        $this->login($tenantUser);
        $this->get('/admin/operator-consumption');
        $this->assertResponseCode(403);
    }

    public function testPerTenantFiguresAreScoped(): void
    {
        // The service is exercised inside a transaction (its tenant switch uses
        // transaction-local set_config) with the operator's own context set, mirroring
        // the request. Each tenant's figures must reflect only that tenant.
        $conn = ConnectionManager::get('default');
        $conn->begin();
        try {
            $conn->execute(
                "SELECT set_config('app.current_tenant_id', :t, true)",
                ['t' => TenantService::DEFAULT_TENANT_ID],
            );
            $rows = (new OperatorConsumptionService())->perTenant();

            $byId = [];
            foreach ($rows as $r) {
                $byId[$r['tenant_id']] = $r;
            }
            $this->assertArrayHasKey($this->tenantA, $byId, 'tenant A is listed');
            $this->assertArrayHasKey($this->tenantB, $byId, 'tenant B is listed');

            $a = $byId[$this->tenantA]['summary'];
            $this->assertNotNull($a);
            $this->assertSame(1, $a['module_count'], 'A has one enabled module');
            $tablesA = array_column($a['footprint']['tables'], 'rows', 'key');
            $this->assertArrayHasKey('automation_rules', $tablesA, "A's rule is counted");

            $b = $byId[$this->tenantB]['summary'];
            $this->assertNotNull($b);
            $this->assertSame(0, $b['module_count'], 'B has no modules — A never leaks in');
            $tablesB = array_column($b['footprint']['tables'], 'rows', 'key');
            $this->assertArrayNotHasKey('automation_rules', $tablesB, "A's rule never appears for B");

            // The operator's own tenant context is restored after the per-tenant reads.
            $restored = $conn->execute('SELECT core.current_tenant() AS t')->fetch('assoc');
            $this->assertSame(TenantService::DEFAULT_TENANT_ID, (string)$restored['t'], 'operator context restored');
        } finally {
            $conn->rollback();
        }
    }
}
