<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Update;

use App\Model\Entity\ContractRegistration;
use App\Service\I18n\LanguagePackStore;
use App\Service\Module\LifecycleException;
use App\Service\Module\ModuleLifecycle;
use App\Service\Registry\ContractRegistry;
use App\Service\Settings\SettingsManager;
use App\Service\Update\RecoveryPoint;
use App\Service\Update\UpdateManager;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Throwable;

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
    private const CONSUMER_KEY = 'ztest_upd_con';
    private const PROVIDED_CONTRACT = 'ztest_upd.service.x';
    private string $v1 = '';
    private string $v2 = '';
    private string $v2bad = '';
    private bool $prevSig = true;
    /**
     * @var array<string,mixed>
     */
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
            /**
             * @var list<string>
             */
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

    public function testUpdateImportsLanguagePackForNewVersion(): void
    {
        // Both packages ship a locale file (locales/de_DE/<key>.po).
        $this->addLocale($this->v1, '1.0.0');
        $this->addLocale($this->v2, '1.1.0');
        (new ModuleLifecycle())->install($this->v1);

        $this->manager()->updateModule(self::KEY, $this->v2);

        // The managed locale store is version-keyed: the update must import the
        // NEW version's pack (install imported only 1.0.0) — otherwise the
        // module's i18n domain resolves to nothing after the update and every
        // runtime string degrades to its raw key.
        $row = ConnectionManager::get('default')->execute(
            "SELECT id FROM language_packs WHERE component_key = :k AND version = '1.1.0' AND locale = 'de_DE'",
            ['k' => self::KEY],
        )->fetch('assoc');
        $this->assertNotEmpty($row, 'Sprachpaket der neuen Version muss beim Update importiert werden.');
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

    /**
     * Updating a PROVIDER module must NOT sever the integrations docked onto its
     * contracts. The old path did `DELETE FROM contracts WHERE owner_module_key`
     * and rebuilt them — but fk_registrations_contract / fk_bindings_contract are
     * ON DELETE CASCADE, so every downstream connector's consumer registration +
     * binding got hard-deleted and nothing rebuilt them (the connector went
     * silently inert after each provider update). The fix upserts contracts in
     * place, keeping their stable id, so downstream rows survive untouched.
     */
    public function testUpdatePreservesDownstreamConsumerRegistration(): void
    {
        $base = dirname($this->v1);
        $prov1 = $this->buildProviderWithContract($base . '/prov1', '1.0.0', [
            '001_init.sql' => "CREATE TABLE widget (id uuid NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY, name text);\n-- @DOWN\nDROP TABLE widget;\n",
        ]);
        (new ModuleLifecycle())->install($prov1);
        (new ModuleLifecycle())->activate(self::KEY);

        // A downstream connector docks a CONSUMER registration onto the contract.
        $conn = ConnectionManager::get('default');
        $conn->execute(
            'INSERT INTO modules (module_key, name, version, type, core_compatibility, status, manifest) '
            . "VALUES (:k, 'ZZ Downstream', '1.0.0', 'extension', '>=1.0.0', 'active', '{}'::jsonb)",
            ['k' => self::CONSUMER_KEY],
        );
        $registry = new ContractRegistry();
        $registry->register(self::CONSUMER_KEY, self::PROVIDED_CONTRACT, ContractRegistration::TYPE_CONSUMER, [
            'moduleVersion' => '1.0.0',
        ]);

        $before = $conn->execute('SELECT id FROM contracts WHERE name = :n', ['n' => self::PROVIDED_CONTRACT])->fetch('assoc');
        $this->assertNotFalse($before, 'Contract muss durch die Provider-Installation existieren.');
        $this->assertTrue($this->consumerActive(), 'Consumer muss vor dem Update aktiv sein.');
        $this->assertTrue($registry->isBindingActive(self::CONSUMER_KEY, self::PROVIDED_CONTRACT));
        // The provider's OWN service-provider registration is active after activate().
        $this->assertTrue($this->ownProviderActive(), 'Provider-Registrierung muss vor dem Update aktiv sein.');

        // Update the provider (adds migration 002 -> a real update).
        $prov2 = $this->buildProviderWithContract($base . '/prov2', '1.1.0', [
            '001_init.sql' => "CREATE TABLE widget (id uuid NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY, name text);\n-- @DOWN\nDROP TABLE widget;\n",
            '002_add.sql' => "ALTER TABLE widget ADD COLUMN qty integer;\n-- @DOWN\nALTER TABLE widget DROP COLUMN qty;\n",
        ]);
        $result = $this->manager()->updateModule(self::KEY, $prov2);
        $this->assertSame('1.1.0', $result['version']);

        // The contract kept its STABLE id (no delete-and-recreate)...
        $after = $conn->execute('SELECT id FROM contracts WHERE name = :n', ['n' => self::PROVIDED_CONTRACT])->fetch('assoc');
        $this->assertNotFalse($after);
        $this->assertSame($before['id'], $after['id'], 'Contract-id muss über das Update stabil bleiben.');
        // ...so the downstream consumer's registration + binding SURVIVE untouched.
        $this->assertTrue($this->consumerActive(), 'Downstream-Consumer-Registrierung muss das Provider-Update überleben.');
        $this->assertTrue($registry->isBindingActive(self::CONSUMER_KEY, self::PROVIDED_CONTRACT));
        // ...and the provider's OWN service registration is re-registered by the
        // update (reactivateRegistrations must cover services_registered).
        $this->assertTrue($this->ownProviderActive(), 'Provider-Registrierung muss das Update überleben (services_registered).');
    }

    /**
     * Updating a provider to a manifest that DROPS a still-consumed contract must
     * not hard-delete the downstream consumer (which fk_registrations_contract ON
     * DELETE CASCADE would do). The prune soft-deactivates the contract instead, so
     * its stable id — and every registration docked onto it — survives, recoverable.
     */
    public function testUpdateDroppingProvidedContractSoftKeepsDownstreamConsumer(): void
    {
        $base = dirname($this->v1);
        $init = "CREATE TABLE widget (id uuid NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY, name text);\n-- @DOWN\nDROP TABLE widget;\n";
        $prov1 = $this->buildProviderWithContracts(
            $base . '/pdrop1',
            '1.0.0',
            ['ztest_upd.svc.keep', self::PROVIDED_CONTRACT],
            ['001_init.sql' => $init],
        );
        (new ModuleLifecycle())->install($prov1);

        $conn = ConnectionManager::get('default');
        $conn->execute(
            'INSERT INTO modules (module_key, name, version, type, core_compatibility, status, manifest) '
            . "VALUES (:k, 'ZZ Downstream', '1.0.0', 'extension', '>=1.0.0', 'active', '{}'::jsonb)",
            ['k' => self::CONSUMER_KEY],
        );
        (new ContractRegistry())->register(self::CONSUMER_KEY, self::PROVIDED_CONTRACT, ContractRegistration::TYPE_CONSUMER, [
            'moduleVersion' => '1.0.0',
        ]);
        $this->assertTrue($this->consumerActive());

        // Update drops PROVIDED_CONTRACT (keeps only the other one).
        $prov2 = $this->buildProviderWithContracts(
            $base . '/pdrop2',
            '1.1.0',
            ['ztest_upd.svc.keep'],
            [
                '001_init.sql' => $init,
                '002_add.sql' => "ALTER TABLE widget ADD COLUMN qty integer;\n-- @DOWN\nALTER TABLE widget DROP COLUMN qty;\n",
            ],
        );
        $this->manager()->updateModule(self::KEY, $prov2);

        // The dropped contract is SOFT-deactivated (row + id kept, not hard-deleted).
        $c = $conn->execute('SELECT active FROM contracts WHERE name = :n', ['n' => self::PROVIDED_CONTRACT])->fetch('assoc');
        $this->assertNotFalse($c, 'Fallengelassener Contract darf nicht hart geloescht werden.');
        $this->assertFalse((bool)$c['active'], 'Fallengelassener Contract muss soft-deaktiviert sein.');
        // The downstream consumer registration ROW survived (no FK CASCADE delete).
        $row = $conn->execute(
            'SELECT 1 FROM contract_registrations cr JOIN contracts c ON c.id = cr.contract_id '
            . 'WHERE c.name = :c AND cr.module_key = :m',
            ['c' => self::PROVIDED_CONTRACT, 'm' => self::CONSUMER_KEY],
        )->fetch();
        $this->assertNotFalse($row, 'Downstream-Consumer-Registrierung darf nicht per FK-CASCADE geloescht werden.');
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
            // The throwaway widget table is not tenant data here (this test exercises the
            // update path, not tenancy) — declare it module-global so the Inc 9 gate skips it.
            'tables' => [
                ['table' => 'widget', 'scope' => 'global', 'reason' => 'Update-Pfad-Test-Fixture; keine Tenant-Daten.'],
            ],
        ]));
        foreach ($migrations as $name => $sql) {
            file_put_contents($dir . '/migrations/' . $name, $sql);
        }

        return $dir;
    }

    /**
     * Like buildModule(), but the manifest also PROVIDES a service contract other
     * modules can dock onto — the fixture for the downstream-preservation test.
     *
     * @param array<string, string> $migrations
     */
    private function buildProviderWithContract(string $dir, string $version, array $migrations): string
    {
        @mkdir($dir . '/migrations', 0o775, true);
        file_put_contents($dir . '/manifest.json', json_encode([
            'id' => self::KEY,
            'name' => 'Update Test Provider',
            'version' => $version,
            'type' => 'main',
            'edition' => 'free',
            'description' => 'Testmodul mit bereitgestelltem Service-Contract.',
            'publisher' => 'Fertura Test',
            'php_namespace' => 'ZtestUpd',
            'core_compatibility' => '>=1.0.0 <2.0.0',
            'requires_license' => false,
            'dependencies' => [],
            'permissions' => [
                ['resource_type' => 'widget', 'name' => self::KEY . '.widget', 'is_scoped' => false],
            ],
            'tables' => [
                ['table' => 'widget', 'scope' => 'global', 'reason' => 'Update-Pfad-Test-Fixture; keine Tenant-Daten.'],
            ],
            'contracts_provided' => [
                ['name' => self::PROVIDED_CONTRACT, 'type' => 'service', 'version' => '1.0.0', 'error_behavior' => 'reject'],
            ],
            // The module also PROVIDES the service (its own provider registration is
            // created via services_registered) — exercises that the update path
            // re-registers services, not only resolvers/collectors/events/consumers.
            'services_registered' => [
                ['contract' => self::PROVIDED_CONTRACT, 'class' => 'ZtestUpd\\Service\\Impl'],
            ],
        ]));
        foreach ($migrations as $name => $sql) {
            file_put_contents($dir . '/migrations/' . $name, $sql);
        }

        return $dir;
    }

    /**
     * Provider package declaring an arbitrary list of provided service contracts
     * (fixture for the contract-drop / soft-deactivate test).
     *
     * @param list<string>          $contractNames
     * @param array<string, string> $migrations
     */
    private function buildProviderWithContracts(string $dir, string $version, array $contractNames, array $migrations): string
    {
        @mkdir($dir . '/migrations', 0o775, true);
        $provided = array_map(static fn(string $n): array => [
            'name' => $n, 'type' => 'service', 'version' => '1.0.0', 'error_behavior' => 'reject',
        ], $contractNames);
        file_put_contents($dir . '/manifest.json', json_encode([
            'id' => self::KEY,
            'name' => 'Update Test Provider',
            'version' => $version,
            'type' => 'main',
            'edition' => 'free',
            'description' => 'Testmodul mit mehreren Service-Contracts.',
            'publisher' => 'Fertura Test',
            'php_namespace' => 'ZtestUpd',
            'core_compatibility' => '>=1.0.0 <2.0.0',
            'requires_license' => false,
            'dependencies' => [],
            'permissions' => [
                ['resource_type' => 'widget', 'name' => self::KEY . '.widget', 'is_scoped' => false],
            ],
            'tables' => [
                ['table' => 'widget', 'scope' => 'global', 'reason' => 'Update-Pfad-Test-Fixture; keine Tenant-Daten.'],
            ],
            'contracts_provided' => $provided,
        ]));
        foreach ($migrations as $name => $sql) {
            file_put_contents($dir . '/migrations/' . $name, $sql);
        }

        return $dir;
    }

    private function consumerActive(): bool
    {
        $row = ConnectionManager::get('default')->execute(
            'SELECT cr.active FROM contract_registrations cr JOIN contracts c ON c.id = cr.contract_id '
            . 'WHERE c.name = :c AND cr.module_key = :m',
            ['c' => self::PROVIDED_CONTRACT, 'm' => self::CONSUMER_KEY],
        )->fetch('assoc');

        return $row !== false && (bool)$row['active'];
    }

    /** The updated module's OWN active provider registration on its service contract. */
    private function ownProviderActive(): bool
    {
        $row = ConnectionManager::get('default')->execute(
            'SELECT cr.active FROM contract_registrations cr JOIN contracts c ON c.id = cr.contract_id '
            . "WHERE c.name = :c AND cr.module_key = :m AND cr.registration_type = 'provider'",
            ['c' => self::PROVIDED_CONTRACT, 'm' => self::KEY],
        )->fetch('assoc');

        return $row !== false && (bool)$row['active'];
    }

    /** Ships a minimal de_DE catalog with the package (domain = module key). */
    private function addLocale(string $dir, string $version): void
    {
        @mkdir($dir . '/locales/de_DE', 0o775, true);
        file_put_contents(
            $dir . '/locales/de_DE/' . self::KEY . '.po',
            "msgid \"\"\nmsgstr \"\"\n\nmsgid \"t.title\"\nmsgstr \"Titel $version\"\n",
        );
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        try {
            (new ModuleLifecycle())->delete(self::KEY);
        } catch (Throwable) {
        }
        foreach (
            [
            'DROP SCHEMA IF EXISTS mod_ztest_upd CASCADE',
            'DELETE FROM contracts WHERE owner_module_key = :k',
            'DELETE FROM resources WHERE module_key = :k',
            'DELETE FROM update_history WHERE component_key = :k',
            'DELETE FROM language_packs WHERE component_key = :k',
            'DELETE FROM modules WHERE module_key = :k',
            ] as $sql
        ) {
            try {
                $conn->execute($sql, str_contains($sql, ':k') ? ['k' => self::KEY] : []);
            } catch (Throwable) {
            }
        }
        // Downstream-connector fixture of the contract-preservation test.
        foreach (
            [
            'DELETE FROM contract_registrations WHERE module_key = :c',
            'DELETE FROM capability_bindings WHERE module_key = :c',
            'DELETE FROM modules WHERE module_key = :c',
            ] as $sql
        ) {
            try {
                $conn->execute($sql, ['c' => self::CONSUMER_KEY]);
            } catch (Throwable) {
            }
        }
        $this->rrmdir(ROOT . '/modules/' . self::KEY);
        $this->rrmdir(ROOT . '/modules/' . self::KEY . '.bak');
        $this->rrmdir((new LanguagePackStore())->base() . '/' . self::KEY);
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
