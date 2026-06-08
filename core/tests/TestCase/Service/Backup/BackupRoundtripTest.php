<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Backup;

use App\Service\Backup\BackupService;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Integrationstest des Backup/Restore-Roundtrips (Kap. 20.1.2, E53/E56) gegen
 * die Test-DB: echtes Erstellen (pg_dump + Stores in ein verifiziertes ZIP),
 * Prüfsummen-Verifikation, nicht-destruktiver Probe-Restore in eine Scratch-DB
 * und die AES-256-Verschlüsselung (richtiges vs. falsches Passwort).
 *
 * @group slow
 */
class BackupRoundtripTest extends TestCase
{
    private string $tmpDir = '';
    /** @var list<string> */
    private array $created = [];
    /** @var array<string,mixed> */
    private array $prev = [];

    protected function setUp(): void
    {
        parent::setUp();
        $sm = new SettingsManager();
        // Vorherige Werte sichern.
        foreach (['backup.verify_on_create', 'backup.min_free_mb', 'backup.password'] as $k) {
            $this->prev[$k] = $sm->get('core', $k, null);
        }
        // Probe-Restore beim Create aus (separat getestet); Preflight entschärfen;
        // unverschlüsselt starten.
        $sm->set('core', 'backup.verify_on_create', false);
        $sm->set('core', 'backup.min_free_mb', 0);
        $sm->set('core', 'backup.password', '');

        $this->tmpDir = sys_get_temp_dir() . '/fertura_bktest_' . bin2hex(random_bytes(5));
        @mkdir($this->tmpDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $svc = new BackupService();
        foreach ($this->created as $id) {
            try {
                $svc->delete($id);
            } catch (\Throwable) {
                // best effort
            }
        }
        $sm = new SettingsManager();
        foreach ($this->prev as $k => $v) {
            $sm->set('core', $k, $v ?? '');
        }
        $this->rrmdir($this->tmpDir);
        parent::tearDown();
    }

    public function testCreateVerifyTestRestore(): void
    {
        $id = (new BackupService())->context('cli')->create('integration', null, $this->tmpDir);
        $this->created[] = $id;
        $this->assertNotSame('', $id);

        $rec = (new BackupService())->get($id);
        $this->assertNotNull($rec);
        $this->assertSame('complete', $rec['status']);
        $this->assertSame('integration', $rec['note']);

        // Prüfsummen-Verifikation des geschriebenen Archivs.
        $verify = (new BackupService())->verify($id);
        $this->assertTrue($verify['ok'], 'Verifikation: ' . ($verify['reason'] ?? ''));

        // Nicht-destruktiver Probe-Restore in eine Wegwerf-DB.
        $probe = (new BackupService())->testRestore($id);
        $this->assertTrue($probe['ok'], 'Probe-Restore: ' . ($probe['reason'] ?? ''));
        $this->assertGreaterThan(0, $probe['tables'], 'Restaurierte DB muss core-Tabellen haben.');
    }

    public function testEncryptedBackupRejectsWrongPassword(): void
    {
        $sm = new SettingsManager();
        $sm->set('core', 'backup.password', 'Geheim!123');

        $svc = new BackupService();
        $this->assertTrue($svc->encryptionEnabled(), 'Passwort gesetzt -> Verschlüsselung aktiv.');
        $id = $svc->context('cli')->create('verschluesselt', null, $this->tmpDir);
        $this->created[] = $id;

        $rec = (new BackupService())->get($id);
        $this->assertTrue((bool)$rec['encrypted'], 'Archiv muss als verschlüsselt markiert sein.');

        // Mit korrektem Passwort: Verifikation ok.
        $this->assertTrue((new BackupService())->verify($id)['ok']);

        // Mit falschem Passwort: Verifikation schlägt fehl (nichts entschlüsselbar).
        $sm->set('core', 'backup.password', 'falsch');
        $this->assertFalse((new BackupService())->verify($id)['ok']);

        // Für das Aufräumen (delete) wieder das korrekte Passwort setzen.
        $sm->set('core', 'backup.password', 'Geheim!123');
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
