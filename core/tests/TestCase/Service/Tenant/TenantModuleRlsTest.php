<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Tenant;

use App\Service\Module\TenantModuleService;
use App\Service\Tenant\TenantService;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use RuntimeException;

/**
 * Per-tenant module enablement (operator/tenant authz §5, Increment 5.1,
 * Decision 185): the {@see TenantModuleService} grant/revoke + fail-closed read
 * behaviour, and the cross-tenant-leak proof that one tenant never sees another's
 * grants under a non-owner NOBYPASSRLS role.
 *
 * Like {@see AutomationTenantRlsTest}, RLS only bites for a non-owner NOBYPASSRLS
 * role (the owner/superuser the suite otherwise runs as bypasses it), so the
 * cross-tenant assertions switch into a dedicated role via `SET LOCAL ROLE`. The
 * service assertions instead just set the tenant context (`app.current_tenant_id`)
 * the service reads through `core.current_tenant()` — its queries scope `tenant_id`
 * explicitly, so they are correct under both the bypassing owner role and the
 * production role.
 */
class TenantModuleRlsTest extends TestCase
{
    private const ROLE = 'fertura_rls_tmtest';
    private const MOD = 'zztm_mod';

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $c */
        $c = ConnectionManager::get('default');

        return $c;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        // A real (active) module row so the tenant_modules.module_key FK is satisfied;
        // its manifest declares a config_schema (Phase 3) for the config tests.
        $manifest = (string)json_encode(['config_schema' => [
            ['key' => 'foo', 'label' => 'l.foo', 'type' => 'string'],
            ['key' => 'num', 'label' => 'l.num', 'type' => 'int'],
        ]]);
        $this->conn()->execute(
            'INSERT INTO modules (module_key, name, version, type, core_compatibility, manifest, status) '
            . "VALUES (:k, 'ZZ TenantModule', '1.0.0', 'extension', '^1.0.0', CAST(:m AS jsonb), 'active') "
            . 'ON CONFLICT (module_key) DO NOTHING',
            ['k' => self::MOD, 'm' => $manifest],
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = $this->conn();
        // Deleting the tenants and the module both cascade to tenant_modules (FKs).
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zztm_%'");
        $conn->execute('DELETE FROM modules WHERE module_key = :k', ['k' => self::MOD]);
    }

    private function makeTenant(string $key): string
    {
        return (string)$this->conn()->execute(
            'INSERT INTO tenants (key, name) VALUES (:k, :n) RETURNING id',
            ['k' => $key, 'n' => 'ZZ ' . $key],
        )->fetch('assoc')['id'];
    }

    public function testServiceEnablesDisablesAndReadsForCurrentTenant(): void
    {
        $a = $this->makeTenant('zztm_a');
        $b = $this->makeTenant('zztm_b');
        $svc = new TenantModuleService();
        $conn = $this->conn();
        $conn->begin();
        try {
            // Context = tenant A. No grant yet -> fail-closed.
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $a]);
            $this->assertFalse($svc->isEnabled(self::MOD), 'no grant -> not enabled');
            $this->assertSame([], $svc->enabledKeys());

            // Enable, then it is visible to A.
            $svc->enable($a, self::MOD);
            $this->assertTrue($svc->isEnabled(self::MOD));
            $this->assertSame([self::MOD], $svc->enabledKeys());

            // Disable keeps the row but reads fail-closed again.
            $svc->disable($a, self::MOD);
            $this->assertFalse($svc->isEnabled(self::MOD), 'disabled -> not enabled');
            $this->assertSame([], $svc->enabledKeys());

            // Re-enable for A, then switch context to B: B sees nothing (opt-in).
            $svc->enable($a, self::MOD);
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $b]);
            $this->assertFalse($svc->isEnabled(self::MOD), 'tenant B has no grant');
            $this->assertSame([], $svc->enabledKeys());
        } finally {
            $conn->rollback(); // discards the grants + the local tenant context
        }
    }

    public function testOperatorTenantIsModuleFreeInMultiOrgMode(): void
    {
        $operator = TenantService::DEFAULT_TENANT_ID;
        $svc = new TenantModuleService();
        $conn = $this->conn();
        $conn->begin();
        try {
            $conn->execute(
                'INSERT INTO tenant_modules (tenant_id, module_key, enabled) VALUES (:t, :k, true) '
                . 'ON CONFLICT (tenant_id, module_key) DO UPDATE SET enabled = true',
                ['t' => $operator, 'k' => self::MOD],
            );
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $operator]);

            // single_org (the default): the operator tenant may use its module.
            $this->assertTrue($svc->isEnabled(self::MOD), 'single_org: operator module enabled');

            // multi_org: the operator/default tenant owns no modules — the enabled grant
            // reads as NOT enabled (operator-tenant design §5b, Option B).
            $this->setTenantMode('multi_org');
            $this->assertFalse($svc->isEnabled(self::MOD), 'multi_org: operator owns no modules');
            $this->assertSame([], $svc->enabledKeys());

            // enable() refuses the operator tenant (defense; the GUI blocks it earlier).
            $threw = false;
            try {
                $svc->enable($operator, self::MOD);
            } catch (RuntimeException) {
                $threw = true;
            }
            $this->assertTrue($threw, 'enabling a module for the operator tenant is refused in multi_org');
        } finally {
            $conn->rollback();
        }
    }

    /** Sets the GLOBAL core.tenancy.mode setting (rolled back with the test tx). */
    private function setTenantMode(string $mode): void
    {
        $conn = $this->conn();
        $conn->execute("DELETE FROM settings WHERE namespace = 'core' AND config_key = 'tenancy.mode' AND tenant_id IS NULL");
        $conn->execute(
            "INSERT INTO settings (namespace, config_key, value) VALUES ('core', 'tenancy.mode', to_jsonb(:m::text))",
            ['m' => $mode],
        );
    }

    public function testGrantsAreTenantScopedUnderRls(): void
    {
        $a = $this->makeTenant('zztm_a');
        $b = $this->makeTenant('zztm_b');
        $this->grant($a);
        $this->grant($b);
        $this->ensureRole();

        $this->assertSame(1, $this->countAsRole($a), 'tenant A sees only its own grant');
        $this->assertSame(1, $this->countAsRole($b), 'tenant B sees only its own grant');
        $this->assertSame(0, $this->countAsRole(''), 'no tenant context -> fail-closed, sees nothing');
    }

    public function testTableHasTenantIdColumnAndRls(): void
    {
        $conn = $this->conn();
        $rls = $conn->execute(
            'SELECT relrowsecurity FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . "WHERE n.nspname = 'core' AND c.relname = 'tenant_modules'",
        )->fetch('assoc');
        $this->assertTrue((bool)$rls['relrowsecurity'], 'tenant_modules must have RLS enabled');

        $col = $conn->execute(
            'SELECT 1 AS ok FROM information_schema.columns '
            . "WHERE table_schema = 'core' AND table_name = 'tenant_modules' AND column_name = 'tenant_id'",
        )->fetch('assoc');
        $this->assertNotFalse($col, 'tenant_modules must have a tenant_id column');
    }

    public function testConfigSchemaIsReadFromManifest(): void
    {
        $schema = (new TenantModuleService())->configSchema(self::MOD);
        $this->assertCount(2, $schema);
        $this->assertSame('foo', $schema[0]['key'] ?? null);
        $this->assertSame('int', $schema[1]['type'] ?? null);
    }

    public function testConfigRoundTripKeepsOnlySchemaKeysAndSurvivesDisable(): void
    {
        $a = $this->makeTenant('zztm_a');
        $svc = new TenantModuleService();
        $conn = $this->conn();
        $conn->begin();
        try {
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $a]);
            // setConfig requires a grant row (enable first); unknown keys are dropped.
            $svc->enable($a, self::MOD);
            $svc->setConfig($a, self::MOD, ['foo' => 'bar', 'num' => 7, 'unknown' => 'x']);

            $cfg = $svc->config(self::MOD); // current tenant = A
            $this->assertSame('bar', $cfg['foo'] ?? null);
            $this->assertSame(7, $cfg['num'] ?? null);
            $this->assertArrayNotHasKey('unknown', $cfg, 'keys outside config_schema are dropped');

            // Disabling keeps the stored config (a later re-enable preserves it).
            $svc->disable($a, self::MOD);
            $this->assertSame('bar', $svc->config(self::MOD)['foo'] ?? null);
        } finally {
            $conn->rollback();
        }
    }

    /** Inserts an (enabled) grant for $tenantId as the owner role (explicit tenant_id). */
    private function grant(string $tenantId): void
    {
        $this->conn()->execute(
            'INSERT INTO tenant_modules (tenant_id, module_key, enabled) VALUES (:t, :k, true) '
            . 'ON CONFLICT (tenant_id, module_key) DO NOTHING',
            ['t' => $tenantId, 'k' => self::MOD],
        );
    }

    private function ensureRole(): void
    {
        $conn = $this->conn();
        $conn->execute(
            "DO $$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='" . self::ROLE . "') THEN "
            . 'CREATE ROLE ' . self::ROLE . ' NOLOGIN NOBYPASSRLS; END IF; END $$;',
        );
        $conn->execute('GRANT USAGE ON SCHEMA core TO ' . self::ROLE);
        $conn->execute('GRANT SELECT ON core.tenant_modules TO ' . self::ROLE);
    }

    /** Counts the test grants visible to the NOBYPASSRLS role under a tenant context. */
    private function countAsRole(string $tenantId): int
    {
        $conn = $this->conn();
        $conn->begin();
        try {
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $tenantId]);
            $conn->execute("SELECT set_config('app.bypass_rls', 'false', true)");
            $conn->execute('SET LOCAL ROLE ' . self::ROLE);

            return (int)$conn->execute(
                'SELECT count(*) c FROM core.tenant_modules WHERE module_key = :k',
                ['k' => self::MOD],
            )->fetch('assoc')['c'];
        } finally {
            $conn->rollback(); // discards SET LOCAL ROLE + set_config
        }
    }
}
