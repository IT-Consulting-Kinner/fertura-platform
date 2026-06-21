<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use App\Controller\Admin\AdminController;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Operator/tenant gate (Betreiber/Mandant-Trennung, Increment 1): an OPERATOR-scoped
 * admin area (platform-wide functions: maintenance, tenants, updates, lifecycle,
 * system backup, …) may be reached ONLY by a user of the operator tenant. A tenant
 * admin holding such a grant is still refused; a tenant-scoped area (user_group_admin)
 * is allowed for a tenant admin (its data is isolated per-controller). Single-org
 * installs run entirely in the operator tenant, so they are unaffected.
 */
class OperatorTenantGateTest extends TestCase
{
    use IntegrationTestTrait;

    private string $tenantAdminId;
    private string $operatorAdminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        foreach (
            [['system_maintenance', 'Wartung', 80], ['user_group_admin', 'Users', 10], ['core_config', 'Core', 60]]
            as [$key, $label, $sort]
        ) {
            $conn->execute(
                'INSERT INTO admin_areas (area_key, label, sort_order) VALUES (:k, :l, :s) ON CONFLICT (area_key) DO NOTHING',
                ['k' => $key, 'l' => $label, 's' => $sort],
            );
        }
        // A NON-operator tenant + an admin in it holding operator AND tenant areas
        // (incl. core_config, so the SearchController::reindex test exercises the
        // operator-tenant check, not merely the grant check).
        $otherTenant = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zzt_gate_' || substr(md5(random()::text), 1, 8), 'Tenant') RETURNING id",
        )->fetch('assoc')['id'];
        $this->tenantAdminId = $this->makeUser('tadmin', $otherTenant);
        $this->grant($this->tenantAdminId, 'system_maintenance');
        $this->grant($this->tenantAdminId, 'user_group_admin');
        $this->grant($this->tenantAdminId, 'core_config');
        // An operator-tenant admin holding the same operator area.
        $this->operatorAdminId = $this->makeUser('opadmin', AdminController::OPERATOR_TENANT_ID);
        $this->grant($this->operatorAdminId, 'system_maintenance');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute('DELETE FROM core.maintenance_session');
        $conn->execute("DELETE FROM user_admin_areas WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@zzgate.local')");
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzgate.local'");
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zzt_gate_%'");
    }

    private function makeUser(string $prefix, string $tenantId): string
    {
        return (string)ConnectionManager::get('default')->execute(
            "INSERT INTO users (username, email, status, tenant_id) VALUES (:u, :e, 'active', :t) RETURNING id",
            [
                'u' => 'zztest_' . $prefix . '_' . bin2hex(random_bytes(3)),
                'e' => $prefix . '_' . bin2hex(random_bytes(3)) . '@zzgate.local',
                't' => $tenantId,
            ],
        )->fetch('assoc')['id'];
    }

    private function grant(string $userId, string $area): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a) ON CONFLICT DO NOTHING',
            ['u' => $userId, 'a' => $area],
        );
    }

    private function login(string $userId): void
    {
        $this->session(['Auth' => ['id' => $userId, 'username' => 'zztest_gate']]);
    }

    public function testOperatorAreaDeniedForTenantAdmin(): void
    {
        // Holds system_maintenance, but is NOT in the operator tenant -> refused.
        $this->login($this->tenantAdminId);
        $this->get('/admin/maintenance');
        $this->assertResponseCode(403);
    }

    public function testTenantAreaAllowedForTenantAdmin(): void
    {
        // A tenant-scoped area is reachable by a tenant admin (data tenant-isolated).
        $this->login($this->tenantAdminId);
        $this->get('/admin/users');
        $this->assertResponseOk();
    }

    public function testOperatorAreaAllowedForOperatorAdmin(): void
    {
        // The same operator area, reached by a user OF the operator tenant -> allowed.
        $this->login($this->operatorAdminId);
        $this->get('/admin/maintenance');
        $this->assertResponseOk();
    }

    public function testGateFreeOperatorPageDeniedForTenantAdmin(): void
    {
        // /admin/health has requiredArea=null but requiresOperator=true: it surfaces
        // platform-wide operator data (worker heartbeats, DB role, backup status), so a
        // tenant admin must be refused even though they hold an admin area.
        $this->login($this->tenantAdminId);
        $this->get('/admin/health');
        $this->assertResponseCode(403);
    }

    public function testGateFreeOperatorPageAllowedForOperatorAdmin(): void
    {
        $this->login($this->operatorAdminId);
        $this->get('/admin/health');
        $this->assertResponseOk();
    }

    public function testReindexDeniedForTenantAdminDespiteGrant(): void
    {
        // SearchController::reindex is requiredArea=null with an inline core_config
        // grant check; the platform-wide RLS-bypass reindex must additionally require
        // the operator tenant. The tenant admin HOLDS core_config but is refused.
        $this->login($this->tenantAdminId);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/admin/search/reindex');

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashElement('flash/error'); // denied (not the success reindex path)
    }
}
