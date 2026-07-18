<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Admin dashboard (Paket 4): the operator/core group renders first as an
 * expanded accordion, with the platform-inventory cards for operators.
 */
class DashboardControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private string $adminId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "INSERT INTO admin_areas (area_key, label, sort_order) VALUES ('user_group_admin', 'Users', 10) "
            . 'ON CONFLICT (area_key) DO NOTHING',
        );
        $this->adminId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zzdash_' . bin2hex(random_bytes(3)), 'e' => bin2hex(random_bytes(3)) . '@zzdash.local'],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $this->adminId, 'a' => 'user_group_admin'],
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
        $conn->execute(
            'DELETE FROM user_admin_areas WHERE user_id IN '
            . "(SELECT id FROM users WHERE email LIKE '%@zzdash.local')",
        );
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzdash.local'");
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zzdash_t_%'");
    }

    public function testDashboardRendersOperatorGroupAsExpandedAccordion(): void
    {
        // Single-org: the seeded user is an operator (default tenant).
        $this->session(['Auth' => ['id' => $this->adminId, 'username' => 'zzdash', 'email' => 'a@zzdash.local']]);
        $this->get('/admin');

        $this->assertResponseOk();
        // The core group renders as an expanded accordion (all groups open).
        $this->assertResponseContains('id="dash-core"');
        $this->assertResponseContains('accordion-collapse collapse show');
        $this->assertResponseNotContains('accordion-button collapsed'); // no folded group
        // Operator sees the platform-inventory cards ('Outbox' is a locale-stable
        // substring of the outbox card label in de+en).
        $this->assertResponseContains('Outbox');
        // The removed "Modules by status" table must not reappear.
        $this->assertResponseNotContains('Modules by status');
    }

    public function testTenantAdminSeesNoOperatorInventoryCards(): void
    {
        // Security boundary: a NON-operator (customer-tenant) admin must see only
        // tenant figures, never the platform-inventory cards.
        $conn = ConnectionManager::get('default');
        $tenantId = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zzdash_t_' || substr(md5(random()::text), 1, 8), 'ZZ Tenant') RETURNING id",
        )->fetch('assoc')['id'];
        $tenantAdmin = (string)$conn->execute(
            "INSERT INTO users (username, email, status, tenant_id) VALUES (:u, :e, 'active', :t) RETURNING id",
            ['u' => 'zzdash_ta_' . bin2hex(random_bytes(3)), 'e' => bin2hex(random_bytes(3)) . '@zzdash.local', 't' => $tenantId],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $tenantAdmin, 'a' => 'user_group_admin'],
        );

        $this->session(['Auth' => ['id' => $tenantAdmin, 'username' => 'zzdash_ta', 'email' => 't@zzdash.local']]);
        $this->get('/admin');

        $this->assertResponseOk();
        // Tenant figures present, operator inventory absent ('Outbox' is a
        // locale-stable substring of the operator-only outbox card).
        $this->assertResponseContains(__('admin.dashboard.card_users_active'));
        $this->assertResponseNotContains('Outbox');
        $this->assertResponseNotContains('Contracts');
    }
}
