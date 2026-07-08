<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Config GUI for the TenantMode setting (operator-tenant design §5b): the enum-style
 * `core.tenancy.mode` setting renders as a dropdown of its allowed values, and its
 * save path enforces the allow-list. `core_config` is operator-scoped, so the acting
 * admin is an operator-tenant (default tenant) user. The test writes the GLOBAL
 * setting without a transaction, so it is reset in cleanup().
 */
class ConfigControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private string $userId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "INSERT INTO admin_areas (area_key, label, sort_order) VALUES ('core_config', 'Core', 60) "
            . 'ON CONFLICT (area_key) DO NOTHING',
        );
        // An operator admin (default tenant, so isOperatorTenant() holds) with core_config.
        $this->userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zzcfg_' . bin2hex(random_bytes(3)), 'e' => 'cfg_' . bin2hex(random_bytes(3)) . '@zzcfg.local'],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $this->userId, 'a' => 'core_config'],
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
            . "(SELECT id FROM users WHERE email LIKE '%@zzcfg.local')",
        );
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzcfg.local'");
        $conn->execute("DELETE FROM settings WHERE namespace = 'core' AND config_key = 'tenancy.mode'");
    }

    private function login(): void
    {
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zzcfg', 'email' => 'c@zzcfg.local']]);
    }

    public function testTenantModeRendersAsDropdown(): void
    {
        $this->login();
        $this->get('/admin/config');

        $this->assertResponseOk();
        // The enum-style setting renders as a <select> with both allowed options.
        $this->assertResponseContains('core.tenancy.mode');
        $this->assertResponseContains('single_org');
        $this->assertResponseContains('multi_org');
    }

    public function testSaveAcceptsAllowedValueAndRejectsOther(): void
    {
        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $conn = ConnectionManager::get('default');
        $stored = static fn(): ?array => $conn->execute(
            "SELECT value #>> '{}' AS v FROM settings "
            . "WHERE namespace = 'core' AND config_key = 'tenancy.mode' AND tenant_id IS NULL",
        )->fetch('assoc') ?: null;

        // An allowed value persists as the global row.
        $this->post('/admin/config/save', ['namespace' => 'core', 'key' => 'tenancy.mode', 'value' => 'multi_org']);
        $this->assertRedirect(['action' => 'index']);
        $this->assertSame('multi_org', $stored()['v'] ?? null);

        // An out-of-allow-list value is rejected (flash error) and does not overwrite.
        $this->post('/admin/config/save', ['namespace' => 'core', 'key' => 'tenancy.mode', 'value' => 'bogus']);
        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashElement('flash/error');
        $this->assertSame('multi_org', $stored()['v'] ?? null, 'the rejected value did not overwrite the stored one');
    }
}
