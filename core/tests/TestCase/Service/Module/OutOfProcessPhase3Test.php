<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Module;

use App\Service\Module\ModuleDbRole;
use App\Service\Module\ModuleHostSupervisor;
use App\Service\Module\ModuleLifecycle;
use App\Service\Schedule\ScheduledTaskRunner;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * E2E-Test der Out-of-Process-Isolation Phase 3 (Kap. 23.16.2): Ein isoliertes
 * Modul stellt einen **Collector-Beitrag** (`core.collector.anonymize`) bereit,
 * der bei der Benutzer-Anonymisierung **im isolierten Host über RPC** ausgeführt
 * wird — inkl. eigener DB-Zugriff (Modul-Rolle) und RLS-Kontext (Bypass) über
 * die RPC-Grenze. Zudem läuft eine **periodische Aufgabe** (core.collector.scheduled)
 * des Moduls beim Tick im isolierten Host (Scheduled-over-RPC), und das Modul
 * stellt einen **Daten-Resolver** bereit (out_of_process erlaubt; nur der
 * Auth-Provider-Slot bleibt config-bedingt ausgenommen).
 *
 * @group slow
 */
class OutOfProcessPhase3Test extends TestCase
{
    use LocatorAwareTrait;

    private const KEY = 'isolated_anon_module';
    private bool $prevSig = true;

    protected function setUp(): void
    {
        parent::setUp();
        $sm = new SettingsManager();
        $this->prevSig = (bool)$sm->get('core', 'require_module_signature', true);
        $sm->set('core', 'require_module_signature', false);
        $this->cleanup();
        // Core-Collector-Contracts (per Migration geseedet, vom Test-Migrator truncatet).
        $conn = ConnectionManager::get('default');
        foreach (['core.collector.anonymize', 'core.collector.scheduled'] as $name) {
            $conn->execute(
                "INSERT INTO contracts (owner_module_key, name, contract_type, version, multi_use, active) "
                . "VALUES ('core', :n, 'collector', '1.0.0', true, true) ON CONFLICT (name) DO NOTHING",
                ['n' => $name],
            );
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        (new SettingsManager())->set('core', 'require_module_signature', $this->prevSig);
        parent::tearDown();
    }

    public function testIsolatedCollectorRunsInHostDuringAnonymization(): void
    {
        $lc = new ModuleLifecycle();
        $lc->install(ROOT . '/tests/Fixture/' . self::KEY, 'out_of_process');
        $lc->activate(self::KEY);
        $this->assertTrue((new ModuleHostSupervisor())->isRunning(self::KEY), 'Host muss laufen.');

        $conn = ConnectionManager::get('default');
        // Benutzer + zugehörige Modul-PII (in der isolierten Modultabelle).
        $u = $conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'p3_' . bin2hex(random_bytes(4)), 'e' => 'p3@invalid.local'],
        )->fetch('assoc');
        $userId = (string)$u['id'];
        $conn->execute(
            'INSERT INTO mod_isolated_anon_module.user_data (owner_id, note) VALUES (:u, :n)',
            ['u' => $userId, 'n' => 'Klarname Müller'],
        );

        try {
            // Anonymisieren -> Core ruft den Collector-Beitrag im isolierten Host auf.
            $users = $this->fetchTable('Users');
            $this->assertTrue($users->anonymize($users->get($userId)));

            // Beweis: der Beitrag IM HOST hat die Modul-PII bereinigt (über die
            // Modul-Rolle + RLS-Bypass über die RPC-Grenze).
            $row = $conn->execute(
                'SELECT note FROM mod_isolated_anon_module.user_data WHERE owner_id = :u',
                ['u' => $userId],
            )->fetch('assoc');
            $this->assertSame('[anonymisiert]', $row['note'], 'Der isolierte Host muss die Modul-PII bereinigt haben.');
        } finally {
            $conn->execute('DELETE FROM users WHERE id = :id', ['id' => $userId]);
        }
    }

    public function testIsolatedScheduledTaskRunsInHost(): void
    {
        $lc = new ModuleLifecycle();
        $lc->install(ROOT . '/tests/Fixture/' . self::KEY, 'out_of_process');
        $lc->activate(self::KEY);

        $conn = ConnectionManager::get('default');
        // Tick -> die periodische Aufgabe des isolierten Moduls läuft IM HOST
        // (über RPC + Modul-Rolle) und schreibt einen Marker in die Modultabelle.
        $ran = (new ScheduledTaskRunner())->tick();
        $this->assertContains('isolated_anon.ping', $ran, 'Die isolierte Aufgabe muss getickt worden sein.');

        $row = $conn->execute(
            "SELECT count(*) AS c FROM mod_isolated_anon_module.user_data WHERE note = 'ticked'",
        )->fetch('assoc');
        $this->assertGreaterThan(0, (int)$row['c'], 'run() muss im isolierten Host einen Marker geschrieben haben.');
    }

    public function testDataResolverModuleIsIsolatable(): void
    {
        // Ein Modul, das einen Daten-Resolver bereitstellt, darf out_of_process
        // installiert werden (Resolver laufen über RPC — nur der config-basierte
        // Auth-Provider-Slot bleibt ausgenommen, siehe OutOfProcessIsolationTest).
        (new ModuleLifecycle())->install(ROOT . '/tests/Fixture/' . self::KEY, 'out_of_process');
        $row = ConnectionManager::get('default')->execute(
            'SELECT isolation FROM modules WHERE module_key = :k',
            ['k' => self::KEY],
        )->fetch('assoc');
        $this->assertSame('out_of_process', $row['isolation']);
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        try {
            (new ModuleHostSupervisor())->stop(self::KEY);
        } catch (\Throwable) {
        }
        try {
            (new ModuleLifecycle())->delete(self::KEY);
        } catch (\Throwable) {
        }
        try {
            (new ModuleDbRole())->drop(self::KEY);
        } catch (\Throwable) {
        }
        foreach ([
            'DROP SCHEMA IF EXISTS mod_isolated_anon_module CASCADE',
            'DELETE FROM contract_registrations WHERE module_key = :k',
            'DELETE FROM contracts WHERE owner_module_key = :k',
            'DELETE FROM resources WHERE module_key = :k',
            'DELETE FROM modules WHERE module_key = :k',
            "DELETE FROM worker_heartbeats WHERE worker_key = 'sched:isolated_anon.ping'",
        ] as $sql) {
            try {
                $conn->execute($sql, str_contains($sql, ':k') ? ['k' => self::KEY] : []);
            } catch (\Throwable) {
            }
        }
        $this->rrmdir(ROOT . '/modules/' . self::KEY);
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
