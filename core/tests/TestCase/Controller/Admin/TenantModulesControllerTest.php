<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use App\Service\Module\TenantModuleService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integration test for the tenant-facing module enable/disable GUI (operator/tenant
 * authz §5, Increment 5.3): a tenant admin lists the installed modules and toggles
 * them for their own tenant; the area is tenant-scoped (a non-operator tenant admin
 * can reach it, unlike an operator area); each toggle is audited.
 */
class TenantModulesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private const MOD = 'zztmctrl_mod';

    private string $userId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        // Seed the tenant_modules admin area (FK target of user_admin_areas; in prod
        // the area set is seeded by migration).
        $conn->execute(
            "INSERT INTO admin_areas (area_key, label, sort_order) VALUES ('tenant_modules', 'Modules', 25) "
            . 'ON CONFLICT (area_key) DO NOTHING',
        );
        // An installed, active module to list and toggle; its manifest declares a
        // config_schema (Phase 3) so the configure GUI has fields to render.
        $manifest = (string)json_encode(['config_schema' => [
            ['key' => 'theme', 'label' => 'l.theme', 'type' => 'string', 'default' => 'light'],
        ]]);
        $conn->execute(
            'INSERT INTO modules (module_key, name, version, type, core_compatibility, manifest, status) '
            . "VALUES (:k, 'ZZ Ctrl Module', '1.0.0', 'extension', '^1.0.0', CAST(:m AS jsonb), 'active') "
            . 'ON CONFLICT (module_key) DO NOTHING',
            ['k' => self::MOD, 'm' => $manifest],
        );
        // A tenant admin (default tenant) holding the tenant_modules area.
        $this->userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztmctrl_' . bin2hex(random_bytes(3)), 'e' => 'tmctrl_' . bin2hex(random_bytes(3)) . '@zztmctrl.local'],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $this->userId, 'a' => 'tenant_modules'],
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
            . "(SELECT id FROM users WHERE email LIKE '%@zztmctrl.local')",
        );
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zztmctrl.local'");
        // Deleting the module cascades its tenant_modules grants.
        $conn->execute('DELETE FROM modules WHERE module_key = :k', ['k' => self::MOD]);
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zztmctrl%'");
        // Reset the global tenant-mode setting (this integration test writes it without
        // a transaction, so it must be cleaned so other tests keep the single_org default).
        $conn->execute("DELETE FROM settings WHERE namespace = 'core' AND config_key = 'tenancy.mode'");
    }

    private function login(): void
    {
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztmctrl', 'email' => 't@zztmctrl.local']]);
    }

    public function testIndexListsActiveModuleAndOffersEnable(): void
    {
        $this->login();
        $this->get('/admin/tenant-modules');

        $this->assertResponseOk();
        $this->assertResponseContains('ZZ Ctrl Module');
        $this->assertResponseContains(self::MOD);
        // No grant yet (fail-closed) -> the Enable action is offered for the module.
        $this->assertResponseContains('/admin/tenant-modules/enable/' . self::MOD);
    }

    public function testEnableThenDisableTogglesGrantAndAudits(): void
    {
        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $conn = ConnectionManager::get('default');

        $this->post('/admin/tenant-modules/enable/' . self::MOD);
        $this->assertRedirect(['action' => 'index']);
        $row = $conn->execute('SELECT enabled FROM tenant_modules WHERE module_key = :k', ['k' => self::MOD])->fetch('assoc');
        $this->assertNotFalse($row, 'a grant row exists after enabling');
        $this->assertTrue((bool)$row['enabled']);
        $this->assertGreaterThanOrEqual(1, (int)$conn->execute(
            "SELECT count(*) c FROM audit_log WHERE action = 'tenant.module.enable' AND module_key = :k",
            ['k' => self::MOD],
        )->fetch('assoc')['c'], 'enable is audited');

        $this->post('/admin/tenant-modules/disable/' . self::MOD);
        $this->assertRedirect(['action' => 'index']);
        $row = $conn->execute('SELECT enabled FROM tenant_modules WHERE module_key = :k', ['k' => self::MOD])->fetch('assoc');
        $this->assertFalse((bool)$row['enabled'], 'the grant row is kept but disabled');
        $this->assertGreaterThanOrEqual(1, (int)$conn->execute(
            "SELECT count(*) c FROM audit_log WHERE action = 'tenant.module.disable' AND module_key = :k",
            ['k' => self::MOD],
        )->fetch('assoc')['c'], 'disable is audited');
    }

    public function testEnableUnknownModuleIsRejected(): void
    {
        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/admin/tenant-modules/enable/zznonexistent_mod');
        $this->assertRedirect(['action' => 'index']);
        $this->assertFalse(
            ConnectionManager::get('default')
                ->execute("SELECT 1 FROM tenant_modules WHERE module_key = 'zznonexistent_mod'")
                ->fetch(),
            'no grant is created for an unknown/inactive module',
        );
    }

    public function testOperatorTenantCannotEnableModulesInMultiOrgMode(): void
    {
        // multi_org mode: the seeded admin is in the operator/default tenant, which then
        // runs operator functions ONLY -> enabling a module for it is refused (operator-
        // tenant design §5b, Option B). The global tenant_mode row is reset in cleanup().
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "INSERT INTO settings (namespace, config_key, value) VALUES ('core', 'tenancy.mode', to_jsonb('multi_org'::text))",
        );

        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/admin/tenant-modules/enable/' . self::MOD);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFalse(
            $conn->execute(
                'SELECT 1 FROM tenant_modules WHERE module_key = :k '
                . "AND tenant_id = '00000000-0000-0000-0000-000000000001' AND enabled",
                ['k' => self::MOD],
            )->fetch(),
            'the operator tenant gets no module grant in multi_org mode',
        );
    }

    public function testNonOperatorTenantAdminCanAccess(): void
    {
        // tenant_modules is a TENANT area: a tenant admin in a NON-operator tenant
        // can reach it (an operator-scoped area would 403 here). Proves the area is
        // classified tenant-scoped, not operator.
        $conn = ConnectionManager::get('default');
        $tid = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zztmctrl-t', 'T') RETURNING id",
        )->fetch('assoc')['id'];
        $uid = (string)$conn->execute(
            "INSERT INTO users (username, email, status, tenant_id) VALUES (:u, :e, 'active', :t) RETURNING id",
            ['u' => 'zztmctrl_t_' . bin2hex(random_bytes(3)), 'e' => 'tt_' . bin2hex(random_bytes(3)) . '@zztmctrl.local', 't' => $tid],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $uid, 'a' => 'tenant_modules'],
        );

        $this->session(['Auth' => ['id' => $uid, 'username' => 'zztmctrl_t', 'email' => 'tt@zztmctrl.local']]);
        $this->get('/admin/tenant-modules');
        $this->assertResponseOk();
    }

    public function testConfigureRendersFormAndSavesConfig(): void
    {
        $conn = ConnectionManager::get('default');
        $tenantId = (string)$conn->execute(
            'SELECT tenant_id FROM users WHERE id = :id',
            ['id' => $this->userId],
        )->fetch('assoc')['tenant_id'];
        (new TenantModuleService())->enable($tenantId, self::MOD); // configure needs a grant row

        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        // GET renders the form built from the module's config_schema.
        $this->get('/admin/tenant-modules/configure/' . self::MOD);
        $this->assertResponseOk();
        $this->assertResponseContains('name="theme"');

        // POST stores the (coerced) value in tenant_modules.config and audits it.
        $this->post('/admin/tenant-modules/configure/' . self::MOD, ['theme' => 'dark']);
        $this->assertRedirect(['action' => 'index']);
        $cfg = (string)$conn->execute(
            'SELECT config FROM tenant_modules WHERE module_key = :k',
            ['k' => self::MOD],
        )->fetch('assoc')['config'];
        $this->assertStringContainsString('dark', $cfg);
        $this->assertGreaterThanOrEqual(1, (int)$conn->execute(
            "SELECT count(*) c FROM audit_log WHERE action = 'tenant.module.configure' AND module_key = :k",
            ['k' => self::MOD],
        )->fetch('assoc')['c'], 'configure is audited');
    }

    public function testConfigureRedirectsWhenModuleNotEnabled(): void
    {
        // The module has a schema but is not enabled for this tenant -> redirect.
        $this->login();
        $this->get('/admin/tenant-modules/configure/' . self::MOD);
        $this->assertRedirect(['action' => 'index']);
    }

    public function testIndexShowsConfigureLinkForEnabledConfigurableModule(): void
    {
        $conn = ConnectionManager::get('default');
        $tenantId = (string)$conn->execute(
            'SELECT tenant_id FROM users WHERE id = :id',
            ['id' => $this->userId],
        )->fetch('assoc')['tenant_id'];
        (new TenantModuleService())->enable($tenantId, self::MOD);

        $this->login();
        $this->get('/admin/tenant-modules');
        $this->assertResponseOk();
        $this->assertResponseContains('/admin/tenant-modules/configure/' . self::MOD);
    }
}
