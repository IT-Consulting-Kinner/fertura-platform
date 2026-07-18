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
        if ($this->adminId !== '') {
            $conn->execute('DELETE FROM user_admin_areas WHERE user_id = :u', ['u' => $this->adminId]);
        }
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzdash.local'");
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
}
