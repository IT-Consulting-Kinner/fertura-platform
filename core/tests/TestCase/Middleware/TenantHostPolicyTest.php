<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Cross-Tenant-Host-Policy (B6): ein angemeldeter Benutzer auf der Domain eines
 * FREMDEN Mandanten wird abgewiesen (403); auf einem nicht zugeordneten Host nicht.
 */
class TenantHostPolicyTest extends TestCase
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
        // Benutzer im DEFAULT-Mandanten.
        $this->userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_host_' . bin2hex(random_bytes(3)), 'e' => 'host_' . bin2hex(random_bytes(3)) . '@zzhost.local'],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $this->userId, 'a' => 'core_config'],
        );
        // FREMDER Mandant mit eigener Domain.
        $conn->execute("INSERT INTO tenants (key, name, domain) VALUES ('zztest-foreign', 'Foreign', 'foreign.zztest')");
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzhost.local'");
        $conn->execute("DELETE FROM tenants WHERE key = 'zztest-foreign'");
    }

    private function login(): void
    {
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_host', 'email' => 'h@zzhost.local']]);
    }

    public function testForeignTenantHostIsForbidden(): void
    {
        $this->login();
        $this->configRequest(['environment' => ['HTTP_HOST' => 'foreign.zztest']]);
        $this->get('/admin/tenants');
        $this->assertResponseCode(403);
    }

    public function testUnmappedHostIsAllowed(): void
    {
        $this->login();
        // localhost ist keinem Mandanten zugeordnet -> keine Policy, normaler Zugriff.
        $this->configRequest(['environment' => ['HTTP_HOST' => 'localhost']]);
        $this->get('/admin/tenants');
        $this->assertResponseOk();
    }
}
