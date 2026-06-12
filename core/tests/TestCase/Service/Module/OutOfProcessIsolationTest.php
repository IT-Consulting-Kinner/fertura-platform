<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Module;

use App\Model\Entity\Contract;
use App\Service\Module\LifecycleException;
use App\Service\Module\ModuleDbRole;
use App\Service\Module\ModuleHostSupervisor;
use App\Service\Module\ModuleLifecycle;
use App\Service\Module\RemoteInvoker;
use App\Service\Module\RpcCapabilityToken;
use App\Service\Registry\ContractRegistry;
use App\Service\Registry\RegistryException;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Throwable;

/**
 * E2E integration test of out-of-process isolation, phase 2 (ch. 23.16.2):
 * installation with a dedicated DB role, migrations run UNDER that role (no
 * superuser code) plus enforced RLS, automatic host start by the supervisor,
 * isolation proof via RPC (__probe), a real service call, and the rejection of
 * non-isolatable modules (service contracts only).
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

        // Role exists and is NOBYPASSRLS.
        $role = $conn->execute(
            'SELECT rolbypassrls, rolcanlogin FROM pg_roles WHERE rolname = :r',
            ['r' => self::ROLE],
        )->fetch('assoc');
        $this->assertNotFalse($role);
        $this->assertFalse((bool)$role['rolbypassrls'], 'Rolle darf RLS nicht umgehen.');

        // Migration ran UNDER the role -> the table is owned by the role.
        $owner = $conn->execute(
            "SELECT tableowner FROM pg_tables WHERE schemaname = 'mod_isolated_module' AND tablename = 'ping_log'",
        )->fetch('assoc');
        $this->assertSame(self::ROLE, $owner['tableowner'] ?? null);

        // RLS is FORCED (applies even to the owner = the role).
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

        // Isolation proof via RPC.
        $probe = (new RemoteInvoker())->invoke(self::KEY, '__probe', []);
        $this->assertFalse($probe['sees_core_database_url'], 'Host darf Core-DATABASE_URL nicht sehen.');
        $this->assertFalse($probe['sees_backup_password'], 'Host darf BACKUP_PASSWORD nicht sehen.');
        $this->assertFalse($probe['can_read_core_users'], 'Host darf core.users nicht lesen.');
        $this->assertTrue($probe['can_read_own_schema'], 'Host muss sein eigenes Schema lesen können.');

        // Real service call through the isolated host.
        $echo = (new RemoteInvoker())->invoke(self::KEY, 'isolated_module.service.echo', ['msg' => 'hallo']);
        $this->assertSame('hallo', $echo['echo']);
        $this->assertSame(5, $echo['length']);

        // Deactivation stops the host.
        $lc->deactivate(self::KEY);
        $this->assertFalse($sup->isRunning(self::KEY), 'Deaktivierung muss den Host stoppen.');
    }

    public function testPerCallTokenRejectsReplayAndForgery(): void
    {
        $lc = new ModuleLifecycle();
        $lc->install($this->fixture(self::KEY), 'out_of_process');
        $lc->activate(self::KEY);
        $this->assertTrue((new ModuleHostSupervisor())->isRunning(self::KEY), 'Host muss laufen.');

        // Shared secret (HMAC key) as the core reads it.
        $secretFile = sys_get_temp_dir() . '/fertura-mod-tokens/' . self::KEY . '.token';
        $secret = trim((string)@file_get_contents($secretFile));
        $this->assertNotSame('', $secret, 'Host-Geheimnis muss provisioniert sein.');
        $socket = (new RemoteInvoker())->socketPath(self::KEY);

        $req = ['contract' => 'isolated_module.service.echo', 'input' => ['msg' => 'x'], 'rls' => []];
        $signed = $req + RpcCapabilityToken::mint($secret, $req);

        // 1) A valid, freshly signed call passes.
        $first = $this->rawRpc($socket, $signed);
        $this->assertSame('x', $first['output']['echo'] ?? null, 'Erster gültiger Aufruf muss durchgehen.');

        // 2) The exact same request again -> same nonce -> replay rejected.
        $replay = $this->rawRpc($socket, $signed);
        $this->assertArrayHasKey('error', $replay, 'Replay derselben Nonce muss abgewiesen werden.');

        // 3) Forgery with the wrong secret -> MAC mismatch -> rejected.
        $forged = $req + RpcCapabilityToken::mint('falsches-geheimnis', $req);
        $bad = $this->rawRpc($socket, $forged);
        $this->assertArrayHasKey('error', $bad, 'MAC mit falschem Geheimnis muss abgewiesen werden.');

        // 4) Tampered arguments with a reused MAC -> rejected.
        $tampered = $signed;
        $tampered['input'] = ['msg' => 'manipuliert'];
        $this->assertArrayHasKey(
            'error',
            $this->rawRpc($socket, $tampered),
            'Geänderte Nutzlast bei altem MAC muss abgewiesen werden.',
        );

        $lc->deactivate(self::KEY);
    }

    public function testLauncherPrefixWrapsHostProcess(): void
    {
        // Configurable launcher prefix (ch. 23.16.2): enables additional OS-level
        // isolation (dedicated user/sandbox) without changing core code. Here a
        // transparent test launcher that writes a marker and then exec's `php`
        // (arguments are passed through).
        $marker = sys_get_temp_dir() . '/fertura_launch_marker_' . bin2hex(random_bytes(5));
        $wrapper = sys_get_temp_dir() . '/fertura_launch_wrap_' . bin2hex(random_bytes(5)) . '.sh';
        file_put_contents(
            $wrapper,
            "#!/bin/sh\n" . 'echo wrapped > ' . escapeshellarg($marker) . "\n" . 'exec "$@"' . "\n",
        );
        @chmod($wrapper, 0o755);

        $sm = new SettingsManager();
        $prevLauncher = (string)$sm->get('core', 'module.host.launcher', '');
        $sm->set('core', 'module.host.launcher', $wrapper);

        try {
            $lc = new ModuleLifecycle();
            $lc->install($this->fixture(self::KEY), 'out_of_process');
            $lc->activate(self::KEY); // -> spawn() prepends the launcher prefix before `php`

            $sup = new ModuleHostSupervisor();
            $this->assertTrue($sup->isRunning(self::KEY), 'Host muss trotz Launcher-Prefix hochkommen.');
            $this->assertFileExists($marker, 'Das Launcher-Prefix muss vor `php` ausgeführt worden sein.');

            // Arguments passed through correctly -> a real service call works.
            $echo = (new RemoteInvoker())->invoke(self::KEY, 'isolated_module.service.echo', ['msg' => 'hi']);
            $this->assertSame('hi', $echo['echo']);

            // stop() still recognizes the (wrapped) host and terminates it cleanly.
            $lc->deactivate(self::KEY);
            $this->assertFalse($sup->isRunning(self::KEY), 'Deaktivierung muss den gewrappten Host stoppen.');
        } finally {
            $sm->set('core', 'module.host.launcher', $prevLauncher);
            @unlink($marker);
            @unlink($wrapper);
        }
    }

    public function testMaliciousMigrationCannotEscalateViaResetRole(): void
    {
        // A malicious migration tries to regain superuser via `RESET ROLE` and
        // write into the core schema. Since the migration runs as the restricted
        // LOGIN role (not SET LOCAL ROLE from a superuser), RESET ROLE returns to
        // the role itself -> the core access fails.
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
        } catch (Throwable $e) {
            $this->assertMatchesRegularExpression('/fehlgeschlagen|permission|denied|recht/i', $e->getMessage());
        }
        // Proof: the core table was NOT created (no escalation).
        $evil = ConnectionManager::get('default')->execute(
            "SELECT 1 FROM information_schema.tables WHERE table_schema='core' AND table_name='evil_escalation'",
        )->fetch();
        $this->assertFalse($evil, 'RESET ROLE darf nicht zu Superuser-Schreibzugriff auf core führen.');

        $this->cleanup('ztest_evil');
        $this->rrmdir($dir);
    }

    public function testFailedInstallRollsBackSchemaRoleAndArtifacts(): void
    {
        // M7/E69: a failure AFTER schema creation (here while registering the
        // contracts — i.e. after DB role, migrations, FORCE RLS, grants and
        // language import) must leave nothing behind. Otherwise a re-install
        // would fail on "already installed" and the DB role would be orphaned.
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

        // Rollback proof: no schema, no module row, no DB role, no
        // contracts/resources/language packs, no copied directory.
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

        // A renewed (regular) install must now succeed again.
        $rec = (new ModuleLifecycle())->install($this->fixture(self::KEY), 'out_of_process');
        $this->assertSame('out_of_process', $rec['isolation']);
    }

    public function testEnforcementRejectsResolverModule(): void
    {
        // The config-like auth provider slot (core.auth.provider) returns an
        // in-process authenticator object and is therefore not reachable over RPC
        // -> out_of_process rejected. (Data resolvers/service/collector/event/
        // scheduled have been allowed since phase 3.)
        $dir = sys_get_temp_dir() . '/fertura_res_' . bin2hex(random_bytes(5));
        @mkdir($dir, 0o775, true);
        file_put_contents($dir . '/manifest.json', json_encode([
            'id' => 'ztest_res', 'name' => 'Resolver', 'version' => '1.0.0', 'type' => 'main',
            'edition' => 'free', 'description' => 'Resolver-Anbieter.', 'publisher' => 'Fertura Test',
            'php_namespace' => 'ZtestRes', 'core_compatibility' => '>=1.0.0 <2.0.0',
            'requires_license' => false, 'dependencies' => [], 'permissions' => [],
            'resolvers_registered' => [
                ['contract' => 'core.auth.provider', 'version' => '>=1.0.0 <2.0.0', 'class' => 'ZtestRes\\Provider'],
            ],
        ]));
        try {
            (new ModuleLifecycle())->install($dir, 'out_of_process');
            $this->fail('Erwartete LifecycleException wegen Resolver.');
        } catch (LifecycleException $e) {
            $this->assertMatchesRegularExpression('/Resolver|RPC/', $e->getMessage());
        } finally {
            $this->rrmdir($dir);
        }
        // No side effects: no schema, no module row.
        $exists = ConnectionManager::get('default')->execute(
            "SELECT 1 FROM information_schema.schemata WHERE schema_name = 'mod_ztest_res'",
        )->fetch();
        $this->assertFalse($exists, 'Abgelehnte Isolation darf kein Schema hinterlassen.');
    }

    // ---- Helpers ------------------------------------------------------------

    private function fixture(string $key): string
    {
        return ROOT . '/tests/Fixture/' . $key;
    }

    /**
     * Sends a **raw** JSON request to the host socket (bypassing RemoteInvoker,
     * to deliberately test replay/forgery) and returns the decoded response.
     *
     * @param array<string, mixed> $req
     * @return array<string, mixed>
     */
    private function rawRpc(string $socket, array $req): array
    {
        $sock = stream_socket_client('unix://' . $socket, $errno, $errstr, 5);
        $this->assertNotFalse($sock, "Socket nicht erreichbar: $errstr");
        stream_set_timeout($sock, 30);
        fwrite($sock, json_encode($req, JSON_UNESCAPED_UNICODE) . "\n");
        $line = fgets($sock);
        fclose($sock);

        return json_decode(trim((string)$line), true) ?: [];
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
        } catch (Throwable) {
        }
        try {
            (new ModuleLifecycle())->delete($key);
        } catch (Throwable) {
        }
        try {
            (new ModuleDbRole())->drop($key);
        } catch (Throwable) {
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
