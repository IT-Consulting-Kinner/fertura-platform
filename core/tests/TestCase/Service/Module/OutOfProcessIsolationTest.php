<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Module;

use App\Model\Entity\Contract;
use App\Service\Module\LifecycleException;
use App\Service\Module\ModuleDbRole;
use App\Service\Module\ModuleHostSupervisor;
use App\Service\Module\ModuleLifecycle;
use App\Service\Module\RemoteInvoker;
use App\Service\Registry\ContractRegistry;
use App\Service\Registry\RegistryException;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * E2E-Integrationstest der Out-of-Process-Isolation, Phase 2 (Kap. 23.16.2):
 * Installation mit eigener DB-Rolle, Migrationen UNTER der Rolle (kein
 * Superuser-Code) + erzwungene RLS, automatischer Host-Start durch den
 * Supervisor, Isolationsnachweis per RPC (__probe), echter Service-Aufruf,
 * sowie die Ablehnung nicht-isolierbarer Module (nur Service-Contracts).
 *
 * @group slow
 */
class OutOfProcessIsolationTest extends TestCase
{
    private const KEY = 'isolated_module';
    private const ROLE = 'mod_isolated_module';
    private bool $prevRequireSig = true;

    protected function setUp(): void
    {
        parent::setUp();
        $sm = new SettingsManager();
        $this->prevRequireSig = (bool)$sm->get('core', 'require_module_signature', true);
        $sm->set('core', 'require_module_signature', false);
        $this->cleanup(self::KEY);
        $this->cleanup('sample_module');
        $this->cleanup('ztest_evil');
    }

    protected function tearDown(): void
    {
        $this->cleanup(self::KEY);
        $this->cleanup('sample_module');
        $this->cleanup('ztest_evil');
        (new SettingsManager())->set('core', 'require_module_signature', $this->prevRequireSig);
        parent::tearDown();
    }

    public function testInstallProvisionsRoleAndRunsMigrationsAsRole(): void
    {
        $rec = (new ModuleLifecycle())->install($this->fixture(self::KEY), 'out_of_process');
        $conn = ConnectionManager::get('default');

        $this->assertSame('out_of_process', $rec['isolation']);
        $this->assertSame(self::ROLE, $rec['db_role']);
        $this->assertNotEmpty($rec['db_role_secret'], 'Rollenpasswort muss (verschlüsselt) gespeichert sein.');

        // Rolle existiert und ist NOBYPASSRLS.
        $role = $conn->execute(
            'SELECT rolbypassrls, rolcanlogin FROM pg_roles WHERE rolname = :r',
            ['r' => self::ROLE],
        )->fetch('assoc');
        $this->assertNotFalse($role);
        $this->assertFalse((bool)$role['rolbypassrls'], 'Rolle darf RLS nicht umgehen.');

        // Migration lief UNTER der Rolle -> Tabelle gehört der Rolle.
        $owner = $conn->execute(
            "SELECT tableowner FROM pg_tables WHERE schemaname = 'mod_isolated_module' AND tablename = 'ping_log'",
        )->fetch('assoc');
        $this->assertSame(self::ROLE, $owner['tableowner'] ?? null);

        // RLS ist ERZWUNGEN (greift auch für den Eigentümer = Rolle).
        $forced = $conn->execute(
            'SELECT c.relforcerowsecurity FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . "WHERE n.nspname = 'mod_isolated_module' AND c.relname = 'ping_log'",
        )->fetch('assoc');
        $this->assertTrue((bool)$forced['relforcerowsecurity'], 'FORCE ROW LEVEL SECURITY muss gesetzt sein.');
    }

    public function testActivateSpawnsSupervisedHostAndIsolationHolds(): void
    {
        $lc = new ModuleLifecycle();
        $lc->install($this->fixture(self::KEY), 'out_of_process');
        $lc->activate(self::KEY);

        $sup = new ModuleHostSupervisor();
        $this->assertTrue($sup->isRunning(self::KEY), 'Aktivierung muss den Host starten.');

        // Isolationsnachweis über RPC.
        $probe = (new RemoteInvoker())->invoke(self::KEY, '__probe', []);
        $this->assertFalse($probe['sees_core_database_url'], 'Host darf Core-DATABASE_URL nicht sehen.');
        $this->assertFalse($probe['sees_backup_password'], 'Host darf BACKUP_PASSWORD nicht sehen.');
        $this->assertFalse($probe['can_read_core_users'], 'Host darf core.users nicht lesen.');
        $this->assertTrue($probe['can_read_own_schema'], 'Host muss sein eigenes Schema lesen können.');

        // Echter Service-Aufruf über den isolierten Host.
        $echo = (new RemoteInvoker())->invoke(self::KEY, 'isolated_module.service.echo', ['msg' => 'hallo']);
        $this->assertSame('hallo', $echo['echo']);
        $this->assertSame(5, $echo['length']);

        // Deaktivieren stoppt den Host.
        $lc->deactivate(self::KEY);
        $this->assertFalse($sup->isRunning(self::KEY), 'Deaktivierung muss den Host stoppen.');
    }

    public function testMaliciousMigrationCannotEscalateViaResetRole(): void
    {
        // Eine bösartige Migration versucht per `RESET ROLE` wieder Superuser zu
        // werden und im core-Schema zu schreiben. Da die Migration über die
        // eingeschränkte LOGIN-Rolle läuft (nicht SET LOCAL ROLE auf Superuser),
        // bleibt RESET ROLE bei der Rolle selbst -> der core-Zugriff scheitert.
        $dir = sys_get_temp_dir() . '/fertura_evil_' . bin2hex(random_bytes(5));
        @mkdir($dir . '/migrations', 0o775, true);
        file_put_contents($dir . '/manifest.json', json_encode([
            'id' => 'ztest_evil', 'name' => 'Evil', 'version' => '1.0.0', 'type' => 'main',
            'edition' => 'free', 'description' => 'Eskalationsversuch.', 'publisher' => 'Fertura Test',
            'php_namespace' => 'ZtestEvil', 'core_compatibility' => '>=1.0.0 <2.0.0',
            'requires_license' => false, 'dependencies' => [], 'permissions' => [],
        ]));
        file_put_contents(
            $dir . '/migrations/001_evil.sql',
            "CREATE TABLE legit (id integer);\nRESET ROLE;\nCREATE TABLE core.evil_escalation (id integer);\n-- @DOWN\nDROP TABLE IF EXISTS legit;\n",
        );

        try {
            (new ModuleLifecycle())->install($dir, 'out_of_process');
            $this->fail('Die Eskalations-Migration hätte fehlschlagen müssen.');
        } catch (\Throwable $e) {
            $this->assertMatchesRegularExpression('/fehlgeschlagen|permission|denied|recht/i', $e->getMessage());
        }
        // Beweis: die core-Tabelle wurde NICHT angelegt (keine Eskalation).
        $evil = ConnectionManager::get('default')->execute(
            "SELECT 1 FROM information_schema.tables WHERE table_schema='core' AND table_name='evil_escalation'",
        )->fetch();
        $this->assertFalse($evil, 'RESET ROLE darf nicht zu Superuser-Schreibzugriff auf core führen.');

        $this->cleanup('ztest_evil');
        $this->rrmdir($dir);
    }

    public function testFailedInstallRollsBackSchemaRoleAndArtifacts(): void
    {
        // M7/E69: ein Fehlschlag NACH der Schema-Erzeugung (hier beim Registrieren
        // der Contracts — also nach DB-Rolle, Migrationen, FORCE-RLS, Grants und
        // Sprachimport) darf nichts zurücklassen. Sonst scheitert ein erneuter
        // Install an „bereits installiert" und die DB-Rolle bliebe verwaist.
        $registry = new class extends ContractRegistry {
            public function registerContract(
                string $ownerModuleKey,
                string $name,
                string $type,
                string $version,
                array $opts = [],
            ): Contract {
                throw new RegistryException('Erzwungener Fehlschlag nach der Schema-Erzeugung (Test).');
            }
        };
        $lc = new ModuleLifecycle($registry);
        $conn = ConnectionManager::get('default');

        try {
            $lc->install($this->fixture(self::KEY), 'out_of_process');
            $this->fail('Install hätte am erzwungenen Contract-Fehler scheitern müssen.');
        } catch (RegistryException $e) {
            $this->assertStringContainsString('Erzwungener Fehlschlag', $e->getMessage());
        }

        // Rollback-Nachweis: kein Schema, keine Modulzeile, keine DB-Rolle,
        // keine Contracts/Ressourcen/Sprachpakete, kein kopiertes Verzeichnis.
        $this->assertFalse(
            $this->rowExists($conn, "SELECT 1 FROM information_schema.schemata WHERE schema_name = 'mod_isolated_module'"),
            'Schema muss zurückgebaut sein.',
        );
        $this->assertFalse(
            $this->rowExists($conn, 'SELECT 1 FROM modules WHERE module_key = :k', ['k' => self::KEY]),
            'Modulzeile muss weg sein.',
        );
        $this->assertFalse(
            $this->rowExists($conn, 'SELECT 1 FROM pg_roles WHERE rolname = :r', ['r' => self::ROLE]),
            'Provisionierte DB-Rolle darf nicht zurückbleiben.',
        );
        $this->assertFalse(
            $this->rowExists($conn, 'SELECT 1 FROM contracts WHERE owner_module_key = :k', ['k' => self::KEY]),
            'Keine Contracts dürfen zurückbleiben.',
        );
        $this->assertFalse(
            $this->rowExists($conn, 'SELECT 1 FROM resources WHERE module_key = :k', ['k' => self::KEY]),
            'Keine Ressourcen dürfen zurückbleiben.',
        );
        $this->assertFalse(
            $this->rowExists($conn, 'SELECT 1 FROM language_packs WHERE component_key = :k', ['k' => self::KEY]),
            'Keine Sprachpakete dürfen zurückbleiben.',
        );
        $this->assertDirectoryDoesNotExist(ROOT . '/modules/' . self::KEY, 'Kopiertes Verzeichnis muss weg sein.');

        // Ein erneuter (regulärer) Install muss jetzt wieder gelingen.
        $rec = (new ModuleLifecycle())->install($this->fixture(self::KEY), 'out_of_process');
        $this->assertSame('out_of_process', $rec['isolation']);
    }

    public function testEnforcementRejectsNonServiceModule(): void
    {
        // sample_module deklariert einen Event-Listener -> nicht isolierbar.
        try {
            (new ModuleLifecycle())->install($this->fixture('sample_module'), 'out_of_process');
            $this->fail('Erwartete LifecycleException wegen nicht-isolierbarer Erweiterungspunkte.');
        } catch (LifecycleException $e) {
            $this->assertMatchesRegularExpression('/Service-Contracts|Event-Listener/', $e->getMessage());
        }
        // Keine Seiteneffekte: kein Schema, keine Modulzeile.
        $exists = ConnectionManager::get('default')->execute(
            "SELECT 1 FROM information_schema.schemata WHERE schema_name = 'mod_sample_module'",
        )->fetch();
        $this->assertFalse($exists, 'Abgelehnte Isolation darf kein Schema hinterlassen.');
    }

    // ---- Helfer -------------------------------------------------------------

    private function fixture(string $key): string
    {
        return ROOT . '/tests/Fixture/' . $key;
    }

    /** @param array<string, mixed> $params */
    private function rowExists($conn, string $sql, array $params = []): bool
    {
        return $conn->execute($sql, $params)->fetch() !== false;
    }

    private function cleanup(string $key): void
    {
        $conn = ConnectionManager::get('default');
        try {
            (new ModuleHostSupervisor())->stop($key);
        } catch (\Throwable) {
        }
        try {
            (new ModuleLifecycle())->delete($key);
        } catch (\Throwable) {
        }
        try {
            (new ModuleDbRole())->drop($key);
        } catch (\Throwable) {
        }
        foreach ([
            "DROP SCHEMA IF EXISTS mod_$key CASCADE",
            'DELETE FROM contracts WHERE owner_module_key = :k',
            'DELETE FROM resources WHERE module_key = :k',
            'DELETE FROM modules WHERE module_key = :k',
            'DELETE FROM language_packs WHERE component_key = :k',
        ] as $sql) {
            try {
                $conn->execute($sql, str_contains($sql, ':k') ? ['k' => $key] : []);
            } catch (\Throwable) {
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
