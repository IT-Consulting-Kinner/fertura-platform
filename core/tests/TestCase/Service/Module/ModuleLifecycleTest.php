<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Module;

use App\Service\Module\LifecycleException;
use App\Service\Module\ModuleLifecycle;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Throwable;

/**
 * Integration test of the module lifecycle (ch. 24) plus the mandatory
 * Row-Level Security requirement and its enforcement (ch. 30.3, E47) against the
 * test DB. Installs the real fixture module, checks schema/contracts/RLS, runs
 * the full lifecycle, and proves that the RLS policy actually scopes rows (own
 * and public rows visible, others' not) — using a NOBYPASSRLS role.
 */
class ModuleLifecycleTest extends TestCase
{
    private const KEY = 'sample_module';
    private const NORLS_KEY = 'ztest_norls';
    private const RLS_ROLE = 'fertura_test_rls';
    private const USER_A = '11111111-1111-7111-8111-111111111111';
    private const USER_B = '22222222-2222-7222-8222-222222222222';

    private string $norlsDir = '';
    private bool $prevRequireSig = true;

    protected function setUp(): void
    {
        parent::setUp();
        // Disable the signature requirement for this test (unsigned fixture).
        $sm = new SettingsManager();
        $this->prevRequireSig = (bool)$sm->get('core', 'require_module_signature', true);
        $sm->set('core', 'require_module_signature', false);

        $this->cleanupModule(self::KEY);
        $this->cleanupModule(self::NORLS_KEY);
    }

    protected function tearDown(): void
    {
        $this->cleanupModule(self::KEY);
        $this->cleanupModule(self::NORLS_KEY);
        $this->rrmdir($this->norlsDir);
        (new SettingsManager())->set('core', 'require_module_signature', $this->prevRequireSig);
        parent::tearDown();
    }

    public function testInstallActivateDeactivateDelete(): void
    {
        $lc = new ModuleLifecycle();
        $conn = ConnectionManager::get('default');

        // --- Install ---
        $rec = $lc->install($this->fixturePath());
        $this->assertSame(self::KEY, $rec['module_key']);
        $this->assertSame('installed_inactive', $rec['status']);

        // Module schema plus RLS-enabled table with policy present.
        $this->assertTrue($this->schemaExists('mod_' . self::KEY));
        $rls = $conn->execute(
            'SELECT (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace '
            . "WHERE n.nspname='mod_sample_module' AND c.relname='ping_log' AND c.relrowsecurity) AS rls, "
            . "(SELECT count(*) FROM pg_policies WHERE schemaname='mod_sample_module') AS pol",
        )->fetch('assoc');
        $this->assertSame(1, (int)$rls['rls'], 'ping_log muss RLS aktiviert haben.');
        $this->assertGreaterThan(0, (int)$rls['pol'], 'Es muss mind. eine Policy geben.');

        // Contract registered.
        $c = $conn->execute(
            "SELECT count(*) n FROM contracts WHERE owner_module_key=:k AND name='sample_module.service.echo'",
            ['k' => self::KEY],
        )->fetch('assoc');
        $this->assertSame(1, (int)$c['n']);

        // --- Activate / Deactivate ---
        $active = $lc->activate(self::KEY);
        $this->assertSame('active', $active['status']);
        $lc->deactivate(self::KEY);
        $this->assertSame('inactive', $this->moduleStatus(self::KEY));

        // --- Delete ---
        $lc->delete(self::KEY);
        $this->assertNull($this->moduleStatus(self::KEY), 'Modulzeile muss weg sein.');
        $this->assertFalse($this->schemaExists('mod_' . self::KEY), 'Schema muss gedroppt sein.');
    }

    public function testRowLevelSecurityScopesRows(): void
    {
        $lc = new ModuleLifecycle();
        $lc->install($this->fixturePath());
        $conn = ConnectionManager::get('default');

        // Three rows as superuser (RLS bypassed): A, B, public (NULL).
        $conn->execute('INSERT INTO mod_sample_module.ping_log (owner_id) VALUES (:a)', ['a' => self::USER_A]);
        $conn->execute('INSERT INTO mod_sample_module.ping_log (owner_id) VALUES (:b)', ['b' => self::USER_B]);
        $conn->execute('INSERT INTO mod_sample_module.ping_log (owner_id) VALUES (NULL)');

        $this->ensureRlsRole();

        // As the NOBYPASSRLS role with context A: only A + public visible = 2.
        $this->assertSame(2, $this->countAsRole(self::USER_A, false));
        // bypass=true -> all 3 visible.
        $this->assertSame(3, $this->countAsRole(self::USER_A, true));
        // Context B (without bypass): B + public = 2, but A's row invisible.
        $this->assertSame(2, $this->countAsRole(self::USER_B, false));

        $lc->delete(self::KEY);
    }

    public function testInstallRejectsScopedResourceWithoutRls(): void
    {
        // Module declares an is_scoped resource, but the migration ships NO RLS
        // -> install must abort (ch. 30.3, E47) and roll back cleanly.
        $this->norlsDir = $this->buildNoRlsModule();
        $this->expectException(LifecycleException::class);
        $this->expectExceptionMessageMatches('/RLS|is_scoped/i');
        try {
            (new ModuleLifecycle())->install($this->norlsDir);
        } finally {
            // Rollback proof: schema and module row must not remain.
            $this->assertFalse($this->schemaExists('mod_' . self::NORLS_KEY));
            $this->assertNull($this->moduleStatus(self::NORLS_KEY));
        }
    }

    // ---- Helpers ------------------------------------------------------------

    private function fixturePath(): string
    {
        return ROOT . '/tests/Fixture/sample_module';
    }

    private function ensureRlsRole(): void
    {
        $conn = ConnectionManager::get('default');
        // Role name is a fixed constant (no parameter binding inside a DO block).
        $conn->execute(
            "DO $$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='" . self::RLS_ROLE . "') THEN "
            . 'CREATE ROLE ' . self::RLS_ROLE . ' NOLOGIN NOBYPASSRLS; END IF; END $$;',
        );
        $conn->execute('GRANT USAGE ON SCHEMA mod_sample_module TO ' . self::RLS_ROLE);
        $conn->execute('GRANT SELECT ON ALL TABLES IN SCHEMA mod_sample_module TO ' . self::RLS_ROLE);
    }

    private function countAsRole(string $userId, bool $bypass): int
    {
        $conn = ConnectionManager::get('default');
        $conn->begin();
        try {
            $conn->execute("SELECT set_config('app.current_user_id', :u, true)", ['u' => $userId]);
            $conn->execute("SELECT set_config('app.bypass_rls', :b, true)", ['b' => $bypass ? 'true' : 'false']);
            $conn->execute('SET LOCAL ROLE ' . self::RLS_ROLE);
            $n = (int)$conn->execute('SELECT count(*) c FROM mod_sample_module.ping_log')->fetch('assoc')['c'];
        } finally {
            $conn->rollback(); // discards SET LOCAL ROLE + set_config
        }

        return $n;
    }

    private function buildNoRlsModule(): string
    {
        $dir = sys_get_temp_dir() . '/fertura_norls_' . bin2hex(random_bytes(5));
        mkdir($dir . '/migrations', 0o775, true);
        file_put_contents($dir . '/manifest.json', json_encode([
            'id' => self::NORLS_KEY,
            'name' => 'No-RLS Test',
            'version' => '1.0.0',
            'type' => 'main',
            'edition' => 'free',
            'description' => 'Testmodul ohne RLS für scoped Ressource.',
            'publisher' => 'Fertura Test',
            'php_namespace' => 'ZtestNorls',
            'core_compatibility' => '>=1.0.0 <2.0.0',
            'requires_license' => false,
            'dependencies' => [],
            'permissions' => [
                ['resource_type' => 'thing', 'name' => self::NORLS_KEY . '.thing', 'is_scoped' => true],
            ],
        ]));
        // Table WITHOUT RLS -> violates the requirement.
        file_put_contents(
            $dir . '/migrations/001_init.sql',
            "CREATE TABLE noscope (id uuid NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY);\n-- @DOWN\nDROP TABLE noscope;\n",
        );

        return $dir;
    }

    private function schemaExists(string $schema): bool
    {
        $r = ConnectionManager::get('default')->execute(
            'SELECT 1 FROM information_schema.schemata WHERE schema_name = :s',
            ['s' => $schema],
        )->fetch();

        return $r !== false;
    }

    private function moduleStatus(string $key): ?string
    {
        $r = ConnectionManager::get('default')->execute(
            'SELECT status FROM modules WHERE module_key = :k',
            ['k' => $key],
        )->fetch('assoc');

        return $r === false ? null : (string)$r['status'];
    }

    private function cleanupModule(string $key): void
    {
        $conn = ConnectionManager::get('default');
        try {
            (new ModuleLifecycle())->delete($key);
        } catch (Throwable) {
            // best effort
        }
        foreach (
            [
            "DROP SCHEMA IF EXISTS mod_$key CASCADE",
            'DELETE FROM contracts WHERE owner_module_key = :k',
            'DELETE FROM resources WHERE module_key = :k',
            'DELETE FROM modules WHERE module_key = :k',
            'DELETE FROM language_packs WHERE component_key = :k',
            ] as $sql
        ) {
            try {
                $conn->execute($sql, str_contains($sql, ':k') ? ['k' => $key] : []);
            } catch (Throwable) {
                // best effort
            }
        }
        $this->rrmdir(ROOT . '/modules/' . $key);
        $this->rrmdir(ROOT . '/language-store/' . $key);
    }

    private function rrmdir(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
