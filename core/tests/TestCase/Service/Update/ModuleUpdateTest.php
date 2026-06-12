<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Update;

use App\Service\Module\LifecycleException;
use App\Service\Module\ModuleLifecycle;
use App\Service\Settings\SettingsManager;
use App\Service\Update\RecoveryPoint;
use App\Service\Update\UpdateManager;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Integration test of the module update path (ch. 24.13/28.14.2, review point 3):
 * migration preview, applying new migrations with a recovery point (only when
 * migrations are pending), downgrade protection, and — crucially — the
 * **rollback cascade over down-migrations** that reverts a failed update to the
 * previous state without destroying already-installed data.
 *
 * @group slow
 */
class ModuleUpdateTest extends TestCase
{
    private const KEY = 'ztest_upd';
    private string $v1 = '';
    private string $v2 = '';
    private string $v2bad = '';
    private bool $prevSig = true;
    /** @var array<string,mixed> */
    private $recovery;

    protected function setUp(): void
    {
        parent::setUp();
        $sm = new SettingsManager();
        $this->prevSig = (bool)$sm->get('core', 'require_module_signature', true);
        $sm->set('core', 'require_module_signature', false);
        $this->cleanup();

        $base = sys_get_temp_dir() . '/fertura_upd_' . bin2hex(random_bytes(5));
        $this->v1 = $this->buildModule($base . '/v1', '1.0.0', [
            '001_init.sql' => "CREATE TABLE widget (id uuid NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY, name text);\n-- @DOWN\nDROP TABLE widget;\n",
        ]);
        $this->v2 = $this->buildModule($base . '/v2', '1.1.0', [
            '001_init.sql' => "CREATE TABLE widget (id uuid NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY, name text);\n-- @DOWN\nDROP TABLE widget;\n",
            '002_add.sql' => "ALTER TABLE widget ADD COLUMN qty integer;\n-- @DOWN\nALTER TABLE widget DROP COLUMN qty;\n",
        ]);
        $this->v2bad = $this->buildModule($base . '/v2bad', '1.1.0', [
            '001_init.sql' => "CREATE TABLE widget (id uuid NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY, name text);\n-- @DOWN\nDROP TABLE widget;\n",
            '002_bad.sql' => "THIS IS NOT VALID SQL AT ALL;\n-- @DOWN\n",
        ]);

        // Stub the recovery point for the test (no real pg_dump).
        $this->recovery = new class extends RecoveryPoint {
            /** @var list<string> */
            public array $calls = [];

            public function create(string $label): string
            {
                $this->calls[] = $label;

                return '/tmp/recpoint_' . $label . '.sql';
            }
        };
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        (new SettingsManager())->set('core', 'require_module_signature', $this->prevSig);
        $this->rrmdir(dirname($this->v1)); // base directory
        parent::tearDown();
    }

    private function manager(): UpdateManager
    {
        return new UpdateManager(recovery: $this->recovery);
    }

    public function testPreviewShowsPendingMigrationAndVersionDelta(): void
    {
        (new ModuleLifecycle())->install($this->v1);
        $preview = $this->manager()->previewModule(self::KEY, $this->v2);

        $this->assertTrue($preview['ok'], 'Preview-Fehler: ' . implode(' ', $preview['errors']));
        $this->assertSame('1.0.0', $preview['current_version']);
        $this->assertSame('1.1.0', $preview['new_version']);
        $this->assertFalse($preview['is_downgrade']);
        $this->assertContains('002_add.sql', $preview['pending_migrations']);
        $this->assertNotContains('001_init.sql', $preview['pending_migrations'], 'Bereits angewendete Migration darf nicht ausstehen.');
    }

    public function testPreviewRejectsDowngrade(): void
    {
        (new ModuleLifecycle())->install($this->v1);
        $older = $this->buildModule(dirname($this->v1) . '/v0', '0.9.0', [
            '001_init.sql' => "CREATE TABLE widget (id uuid NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY, name text);\n-- @DOWN\nDROP TABLE widget;\n",
        ]);
        $preview = $this->manager()->previewModule(self::KEY, $older);
        $this->assertTrue($preview['is_downgrade']);
        $this->assertFalse($preview['ok']);
    }

    public function testUpdateAppliesMigrationBumpsVersionAndCreatesRecoveryPoint(): void
    {
        (new ModuleLifecycle())->install($this->v1);
        $result = $this->manager()->updateModule(self::KEY, $this->v2);

        $this->assertSame('1.1.0', $result['version']);
        // New migration applied -> column qty exists.
        $col = ConnectionManager::get('default')->execute(
            "SELECT 1 FROM information_schema.columns WHERE table_schema='mod_ztest_upd' AND table_name='widget' AND column_name='qty'",
        )->fetch();
        $this->assertNotFalse($col, 'Spalte qty muss durch die Update-Migration entstanden sein.');
        // A recovery point was created (there were pending migrations).
        $this->assertContains('update_module_ztest_upd', $this->recovery->calls);
        // The history logs the success.
        $hist = ConnectionManager::get('default')->execute(
            "SELECT result FROM update_history WHERE component_key='ztest_upd' ORDER BY executed_at DESC LIMIT 1",
        )->fetch('assoc');
        $this->assertSame('success', $hist['result'] ?? null);
    }

    public function testUpdateWithoutPendingMigrationsSkipsRecoveryPoint(): void
    {
        (new ModuleLifecycle())->install($this->v1);
        // Same migration (001), only a version bump -> nothing to execute.
        $v1patch = $this->buildModule(dirname($this->v1) . '/v1patch', '1.0.1', [
            '001_init.sql' => "CREATE TABLE widget (id uuid NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY, name text);\n-- @DOWN\nDROP TABLE widget;\n",
        ]);
        $result = $this->manager()->updateModule(self::KEY, $v1patch);
        $this->assertSame('1.0.1', $result['version']);
        $this->assertSame([], $this->recovery->calls, 'Ohne ausstehende Migrationen kein Wiederherstellungspunkt.');
    }

    public function testFailedUpdateRollsBackVersionAndPreservesInstalledData(): void
    {
        (new ModuleLifecycle())->install($this->v1);
        // Existing data before the update.
        ConnectionManager::get('default')->execute("INSERT INTO mod_ztest_upd.widget (name) VALUES ('vorhanden')");

        try {
            $this->manager()->updateModule(self::KEY, $this->v2bad);
            $this->fail('Erwartete LifecycleException wegen fehlerhafter Migration.');
        } catch (LifecycleException $e) {
            $this->assertStringContainsString('fehlgeschlagen', $e->getMessage());
        }

        $conn = ConnectionManager::get('default');
        // Version rolled back.
        $ver = $conn->execute("SELECT version FROM modules WHERE module_key='ztest_upd'")->fetch('assoc');
        $this->assertSame('1.0.0', $ver['version'] ?? null);
        // The migration applied during install (001) was NOT rolled back ->
        // table + data are preserved (correct, tightly scoped rollback cascade).
        $row = $conn->execute("SELECT name FROM mod_ztest_upd.widget WHERE name='vorhanden'")->fetch('assoc');
        $this->assertSame('vorhanden', $row['name'] ?? null);
    }

    // ---- Helpers ------------------------------------------------------------

    private function buildModule(string $dir, string $version, array $migrations): string
    {
        @mkdir($dir . '/migrations', 0o775, true);
        file_put_contents($dir . '/manifest.json', json_encode([
            'id' => self::KEY,
            'name' => 'Update Test',
            'version' => $version,
            'type' => 'main',
            'edition' => 'free',
            'description' => 'Testmodul für den Update-Pfad.',
            'publisher' => 'Fertura Test',
            'php_namespace' => 'ZtestUpd',
            'core_compatibility' => '>=1.0.0 <2.0.0',
            'requires_license' => false,
            'dependencies' => [],
            'permissions' => [
                ['resource_type' => 'widget', 'name' => self::KEY . '.widget', 'is_scoped' => false],
            ],
        ]));
        foreach ($migrations as $name => $sql) {
            file_put_contents($dir . '/migrations/' . $name, $sql);
        }

        return $dir;
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        try {
            (new ModuleLifecycle())->delete(self::KEY);
        } catch (\Throwable) {
        }
        foreach ([
            'DROP SCHEMA IF EXISTS mod_ztest_upd CASCADE',
            'DELETE FROM contracts WHERE owner_module_key = :k',
            'DELETE FROM resources WHERE module_key = :k',
            'DELETE FROM update_history WHERE component_key = :k',
            'DELETE FROM modules WHERE module_key = :k',
        ] as $sql) {
            try {
                $conn->execute($sql, str_contains($sql, ':k') ? ['k' => self::KEY] : []);
            } catch (\Throwable) {
            }
        }
        $this->rrmdir(ROOT . '/modules/' . self::KEY);
        $this->rrmdir(ROOT . '/modules/' . self::KEY . '.bak');
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
