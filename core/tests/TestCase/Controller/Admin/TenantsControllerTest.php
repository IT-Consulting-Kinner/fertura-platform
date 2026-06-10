<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integrationstest der Mandanten-Admin-GUI: rendert für einen core_config-Admin
 * (prüft Controller + Template + das Modul-UI-Kit im echten Render) und legt an.
 */
class TenantsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "INSERT INTO admin_areas (area_key, label, sort_order) VALUES ('core_config', 'Core', 60) "
            . 'ON CONFLICT (area_key) DO NOTHING',
        );
        $this->userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_tadmin_' . bin2hex(random_bytes(3)), 'e' => 'tadmin_' . bin2hex(random_bytes(3)) . '@zztenant.local'],
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
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zztenant.local'");
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zztest-%'");
    }

    private function login(): void
    {
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_tadmin', 'email' => 't@zztenant.local']]);
    }

    public function testIndexRendersWithUiKit(): void
    {
        $this->login();
        $this->get('/admin/tenants');

        $this->assertResponseOk();
        $this->assertResponseContains('Default');          // Default-Mandant in der Liste
        $this->assertResponseContains('sort=name');         // sortierbarer Kopf (UiKit::sortHeader)
        $this->assertResponseContains('Create tenant');     // Formular (UiKit::fields + Button), i18n en_US
    }

    public function testAddCreatesTenant(): void
    {
        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $key = 'zztest-ctrl-' . bin2hex(random_bytes(2));

        $this->post('/admin/tenants/add', ['key' => $key, 'name' => 'Controller Tenant']);

        $this->assertRedirect(['action' => 'index']);
        $row = ConnectionManager::get('default')->execute(
            'SELECT name FROM tenants WHERE key = :k',
            ['k' => $key],
        )->fetch('assoc');
        $this->assertNotFalse($row);
        $this->assertSame('Controller Tenant', $row['name']);
    }
}
