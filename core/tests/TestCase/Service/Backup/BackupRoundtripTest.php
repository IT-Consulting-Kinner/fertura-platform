<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Backup;

use App\Service\Backup\BackupService;
use App\Service\Settings\SettingsManager;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use RuntimeException;
use Throwable;

/**
 * Integration test of the backup/restore roundtrip (ch. 20.1.2, E53/E56) against
 * the test DB: real creation (pg_dump + writing into a verified ZIP),
 * checksum verification, non-destructive probe restore into a scratch DB,
 * and AES-256 encryption (correct vs. wrong password).
 *
 * @group slow
 */
class BackupRoundtripTest extends TestCase
{
    /** Lifecycle advisory-lock key (mirror of BackupService::LIFECYCLE_LOCK). */
    private const LIFECYCLE_LOCK = 778899001;

    private string $tmpDir = '';
    /**
     * @var list<string>
     */
    private array $created = [];
    /**
     * @var array<string,mixed>
     */
    private array $prev = [];
    private string $holderConn = '';

    protected function setUp(): void
    {
        parent::setUp();
        $sm = new SettingsManager();
        // Save the previous values.
        foreach (['backup.verify_on_create', 'backup.min_free_mb', 'backup.password'] as $k) {
            $this->prev[$k] = $sm->get('core', $k, null);
        }
        // Disable probe restore on create (tested separately); relax preflight;
        // start unencrypted.
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
            } catch (Throwable) {
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

        // Checksum verification of the written archive.
        $verify = (new BackupService())->verify($id);
        $this->assertTrue($verify['ok'], 'Verifikation: ' . ($verify['reason'] ?? ''));

        // Non-destructive probe restore into a throwaway DB.
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

        // With the correct password: verification succeeds.
        $this->assertTrue((new BackupService())->verify($id)['ok']);

        // With the wrong password: verification fails (nothing can be decrypted).
        $sm->set('core', 'backup.password', 'falsch');
        $this->assertFalse((new BackupService())->verify($id)['ok']);

        // Restore the correct password again for cleanup (delete).
        $sm->set('core', 'backup.password', 'Geheim!123');
    }

    /**
     * If another operation holds the lifecycle lock, `create()` must not produce
     * a (DB-vs-storage inconsistent) snapshot without the lock, but must fail
     * loudly (B2). The lock is held here via a **separate** DB session so that the
     * non-blocking `pg_try_advisory_lock` in the service actually returns `false`.
     */
    public function testCreateAbortsWhenLifecycleLockHeld(): void
    {
        $note = 'lock-contention-' . bin2hex(random_bytes(4));

        $holder = $this->separateConnection();
        $got = $holder->execute('SELECT pg_try_advisory_lock(:k) AS ok', ['k' => self::LIFECYCLE_LOCK])->fetch('assoc');
        $this->assertTrue($got['ok'] === true || $got['ok'] === 't', 'Testaufbau: Lock muss in eigener Sitzung greifbar sein.');

        try {
            $threw = false;
            try {
                (new BackupService())->context('cli')->create($note, null, $this->tmpDir);
            } catch (RuntimeException $e) {
                $threw = true;
                $this->assertStringContainsStringIgnoringCase('lock', $e->getMessage());
            }
            $this->assertTrue($threw, 'create() muss bei gehaltenem Lifecycle-Lock werfen statt still fortzufahren.');
        } finally {
            $holder->execute('SELECT pg_advisory_unlock(:k)', ['k' => self::LIFECYCLE_LOCK]);
            $this->dropSeparateConnection();
            // The failed run leaves behind a row marked 'failed'
            // (the INSERT happens before the lock attempt) — clean it up.
            $rows = ConnectionManager::get('default')
                ->execute('SELECT id FROM backups WHERE note = :n', ['n' => $note])->fetchAll('assoc');
            foreach ($rows as $r) {
                try {
                    (new BackupService())->delete((string)$r['id']);
                } catch (Throwable) {
                    // best effort
                }
            }
        }
    }

    /** Separate DB session (second connection) for holding the advisory lock. */
    private function separateConnection(): Connection
    {
        $this->holderConn = 'bk_lockholder';
        if (ConnectionManager::getConfig($this->holderConn) === null) {
            $cfg = ConnectionManager::get('default')->config();
            unset($cfg['name']);
            $cfg['className'] = Connection::class;
            ConnectionManager::setConfig($this->holderConn, $cfg);
        }
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get($this->holderConn);
        $conn->getDriver()->connect();

        return $conn;
    }

    private function dropSeparateConnection(): void
    {
        if ($this->holderConn === '') {
            return;
        }
        try {
            ConnectionManager::get($this->holderConn)->getDriver()->disconnect();
            ConnectionManager::drop($this->holderConn);
        } catch (Throwable) {
            // best effort
        }
        $this->holderConn = '';
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
