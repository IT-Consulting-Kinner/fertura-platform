<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use App\Service\Tenant\TenantService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integration test for the tenants admin GUI: renders for a core_config admin
 * (verifies controller + template + the module UI kit in a real render) and creates.
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
        $this->assertResponseContains('Default'); // default tenant in the list
        $this->assertResponseContains('sort=name'); // sortable header (UiKit::sortHeader)
        $this->assertResponseContains('Create tenant'); // form (UiKit::fields + button), i18n en_US
        // A11y: skip link, main landmark, active nav entry, scoped column headers.
        $this->assertResponseContains('Skip to main content');
        $this->assertResponseContains('id="main"');
        $this->assertResponseContains('aria-current="page"');
        $this->assertResponseContains('scope="col"');
    }

    public function testHandRolledAdminTableHasScopedHeaders(): void
    {
        // Guard for the systematic A11y rollout: *hand-built* admin tables too
        // (not only UiKit-generated ones) carry scope="col" on the column headers.
        $this->login();
        $this->get('/admin/config');

        $this->assertResponseOk();
        $this->assertResponseContains('<th scope="col">'); // Config/index.php is hand-built
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

    public function testAssignUserToTenant(): void
    {
        $conn = ConnectionManager::get('default');
        $tid = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zztest-assign', 'Assign') RETURNING id",
        )->fetch('assoc')['id'];
        $uid = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_assignee_' . bin2hex(random_bytes(3)), 'e' => 'assignee@zztenant.local'],
        )->fetch('assoc')['id'];

        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/admin/tenants/assign', ['email' => 'assignee@zztenant.local', 'tenant_id' => $tid]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertSame($tid, (new TenantService())->tenantIdForUser($uid));
    }

    public function testMalformedTenantIdsFailGracefully(): void
    {
        // Malformed UUID in the form: UUID guard at the service boundary
        // (TenantService) -> error flash + redirect instead of 22P02 -> 500.
        ConnectionManager::get('default')->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active')",
            ['u' => 'zztest_badassign_' . bin2hex(random_bytes(3)), 'e' => 'badassign@zztenant.local'],
        );

        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/admin/tenants/assign', ['email' => 'badassign@zztenant.local', 'tenant_id' => 'garbage']);
        $this->assertRedirect(['action' => 'index']);

        $this->post('/admin/tenants/bulk', ['op' => 'suspend', 'ids' => ['garbage']]);
        $this->assertRedirect(['action' => 'index']);
    }

    public function testBulkSuspendAndActivate(): void
    {
        $conn = ConnectionManager::get('default');
        $id = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zztest-bulk', 'Bulk') RETURNING id",
        )->fetch('assoc')['id'];

        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/admin/tenants/bulk', ['op' => 'suspend', 'ids' => [$id]]);
        $this->assertRedirect(['action' => 'index']);
        $this->assertFalse((bool)$conn->execute('SELECT active FROM tenants WHERE id = :id', ['id' => $id])->fetch('assoc')['active']);

        $this->post('/admin/tenants/bulk', ['op' => 'activate', 'ids' => [$id]]);
        $this->assertTrue((bool)$conn->execute('SELECT active FROM tenants WHERE id = :id', ['id' => $id])->fetch('assoc')['active']);
    }
}
