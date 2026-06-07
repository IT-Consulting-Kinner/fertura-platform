<?php
declare(strict_types=1);

namespace App\Service\Backup;

use App\Application;
use App\Audit\AuditLogger;
use App\Infrastructure\Db;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use ZipArchive;

/**
 * Konsistente Daten-Sicherung & -Wiederherstellung (Kap. 20.1.2, E53).
 *
 * Eine Sicherung umfasst die **gesamte Datenbank** (`pg_dump -Fc`) **und** die
 * persistenten Datei-Stores (`language-store`, `marketplace-data`, `modules`) und
 * wird als **ein einziges ZIP-Archiv** `<id>.zip` abgelegt (alle Daten zusammen).
 * Erstellung unter dem **Lifecycle-Advisory-Lock** → DB↔Storage konsistent;
 * SHA-256 je Artefakt (im Manifest + DB) macht sie **prüfbar**.
 *
 * Ablageort ist konfigurierbar (`backup.path`, Linux-/Windows-Pfade über
 * {@see BackupPath}); Restore kann aus einer beliebigen Archivdatei erfolgen.
 */
class BackupService
{
    /** Muss zum Wert in ModuleLifecycle::LIFECYCLE_LOCK passen. */
    private const LIFECYCLE_LOCK = 778899001;

    /** @var list<string> Persistente Datei-Stores relativ zu ROOT. */
    private const STORES = ['language-store', 'marketplace-data', 'modules'];

    private string $base;

    public function __construct(?string $base = null, private ?AuditLogger $audit = null)
    {
        if ($base === null) {
            try {
                $base = (string)(new SettingsManager())->get('core', 'backup.path', ROOT . '/backups');
            } catch (\Throwable) {
                $base = ROOT . '/backups';
            }
            if (trim($base) === '') {
                $base = ROOT . '/backups';
            }
        }
        $this->base = BackupPath::normalize($base);
        $this->audit ??= new AuditLogger();
    }

    public function base(): string
    {
        return $this->base;
    }

    private function conn()
    {
        return ConnectionManager::get('default');
    }

    /** @return array{host:string,port:string,user:string,pass:string,db:string} */
    private function pg(): array
    {
        $c = Db::privileged()->config();

        return [
            'host' => (string)($c['host'] ?? 'db'),
            'port' => (string)($c['port'] ?? '5432'),
            'user' => (string)($c['username'] ?? 'fertura'),
            'pass' => (string)($c['password'] ?? ''),
            'db' => (string)($c['database'] ?? 'fertura'),
        ];
    }

    private function run(string $cmd): void
    {
        exec($cmd . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            throw new RuntimeException('Kommando fehlgeschlagen (' . $rc . '): ' . implode("\n", array_slice($out, -5)));
        }
    }

    /**
     * Erstellt eine konsistente Sicherung als `<id>.zip`. Gibt die Backup-ID
     * zurück. `$targetDir` überschreibt optional den konfigurierten Ablageort.
     */
    public function create(?string $note, ?string $actorId, ?string $targetDir = null): string
    {
        $dir = ($targetDir !== null && trim($targetDir) !== '')
            ? BackupPath::assertUsable($targetDir)
            : $this->base;
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('Backup-Verzeichnis nicht anlegbar/erreichbar: ' . $dir);
        }

        $id = Uuid::v7()->toRfc4122();
        $zip = $dir . '/' . $id . '.zip';
        $work = $dir . '/' . $id . '.work';
        if (!@mkdir($work, 0o775, true)) {
            throw new RuntimeException('Arbeitsverzeichnis nicht anlegbar: ' . $work);
        }
        $dumpFile = $work . '/database.dump';
        $filesTar = $work . '/files.tar.gz';
        $pg = $this->pg();
        $env = 'PGPASSWORD=' . escapeshellarg($pg['pass']) . ' ';

        $this->conn()->execute(
            'INSERT INTO backups (id, core_version, status, path, note, created_by) '
            . "VALUES (:id, :v, 'creating', :p, :n, :cb)",
            ['id' => $id, 'v' => Application::CORE_VERSION, 'p' => $zip, 'n' => $note, 'cb' => $actorId],
        );

        $locked = $this->lock();
        try {
            $this->run($env . 'pg_dump -h ' . escapeshellarg($pg['host']) . ' -p ' . escapeshellarg($pg['port'])
                . ' -U ' . escapeshellarg($pg['user']) . ' -d ' . escapeshellarg($pg['db'])
                . ' -Fc -f ' . escapeshellarg($dumpFile));

            $present = array_values(array_filter(self::STORES, fn ($s) => is_dir(ROOT . '/' . $s)));
            if ($present !== []) {
                $this->run('tar czf ' . escapeshellarg($filesTar) . ' -C ' . escapeshellarg(ROOT)
                    . ' ' . implode(' ', array_map('escapeshellarg', $present)));
            } else {
                $this->run('tar czf ' . escapeshellarg($filesTar) . ' -C ' . escapeshellarg(ROOT) . ' --files-from /dev/null');
            }
        } catch (\Throwable $e) {
            $this->conn()->execute("UPDATE backups SET status = 'failed' WHERE id = :id", ['id' => $id]);
            @exec('rm -rf ' . escapeshellarg($work));
            throw $e;
        } finally {
            if ($locked) {
                $this->unlock();
            }
        }

        $dbSha = hash_file('sha256', $dumpFile) ?: '';
        $filesSha = hash_file('sha256', $filesTar) ?: '';
        $manifest = [
            'id' => $id,
            'core_version' => Application::CORE_VERSION,
            'database' => ['file' => 'database.dump', 'bytes' => filesize($dumpFile), 'sha256' => $dbSha],
            'files' => ['file' => 'files.tar.gz', 'bytes' => filesize($filesTar), 'sha256' => $filesSha, 'stores' => self::STORES],
        ];

        // Alles in ein ZIP packen.
        $za = new ZipArchive();
        if ($za->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->conn()->execute("UPDATE backups SET status = 'failed' WHERE id = :id", ['id' => $id]);
            @exec('rm -rf ' . escapeshellarg($work));
            throw new RuntimeException('ZIP nicht erstellbar: ' . $zip);
        }
        $za->addFile($dumpFile, 'database.dump');
        $za->addFile($filesTar, 'files.tar.gz');
        $za->addFromString('manifest.json', (string)json_encode($manifest, JSON_PRETTY_PRINT));
        $za->close();
        @exec('rm -rf ' . escapeshellarg($work));

        $this->conn()->execute(
            "UPDATE backups SET status = 'complete', db_bytes = :db, files_bytes = :fb, "
            . 'db_sha256 = :ds, files_sha256 = :fs WHERE id = :id',
            ['db' => $manifest['database']['bytes'], 'fb' => $manifest['files']['bytes'], 'ds' => $dbSha, 'fs' => $filesSha, 'id' => $id],
        );
        $this->audit->log('backup.create', 'backup', $id, ['component' => 'core', 'newValue' => ['note' => $note, 'path' => $zip]]);

        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return $this->conn()->execute(
            'SELECT id, created_at, core_version, status, db_bytes, files_bytes, path, note FROM backups ORDER BY created_at DESC',
        )->fetchAll('assoc');
    }

    /** @return array<string,mixed>|null */
    public function get(string $id): ?array
    {
        $row = $this->conn()->execute('SELECT * FROM backups WHERE id = :id', ['id' => $id])->fetch('assoc');

        return $row === false ? null : $row;
    }

    /**
     * Prüft die Integrität: ZIP lesbar + innere Artefakt-Prüfsummen wie gespeichert.
     *
     * @return array{ok:bool, db:bool, files:bool, reason:?string}
     */
    public function verify(string $id): array
    {
        $row = $this->get($id);
        if ($row === null) {
            return ['ok' => false, 'db' => false, 'files' => false, 'reason' => 'Backup unbekannt.'];
        }
        $zip = (string)$row['path'];
        if (!is_file($zip)) {
            return ['ok' => false, 'db' => false, 'files' => false, 'reason' => 'Archivdatei fehlt: ' . $zip];
        }
        $za = new ZipArchive();
        if ($za->open($zip) !== true) {
            return ['ok' => false, 'db' => false, 'files' => false, 'reason' => 'ZIP nicht lesbar.'];
        }
        $db = $this->entrySha($za, 'database.dump') === (string)$row['db_sha256'];
        $files = $this->entrySha($za, 'files.tar.gz') === (string)$row['files_sha256'];
        $za->close();

        return ['ok' => $db && $files, 'db' => $db, 'files' => $files, 'reason' => $db && $files ? null : 'Prüfsumme abweichend.'];
    }

    /**
     * Probe-Restore in eine Scratch-Datenbank (ohne Produktionseingriff).
     *
     * @return array{ok:bool, tables:int, reason:?string}
     */
    public function testRestore(string $id): array
    {
        $row = $this->get($id);
        if ($row === null) {
            return ['ok' => false, 'tables' => 0, 'reason' => 'Backup unbekannt.'];
        }
        $zip = (string)$row['path'];
        $tmpDump = sys_get_temp_dir() . '/bk_' . substr(md5($zip), 0, 10) . '.dump';
        if (!$this->extractEntry($zip, 'database.dump', $tmpDump)) {
            return ['ok' => false, 'tables' => 0, 'reason' => 'DB-Dump nicht extrahierbar.'];
        }
        $pg = $this->pg();
        $env = 'PGPASSWORD=' . escapeshellarg($pg['pass']) . ' ';
        $scratch = 'fertura_verify_' . substr(str_replace('-', '', $id), 0, 12);
        $base = '-h ' . escapeshellarg($pg['host']) . ' -p ' . escapeshellarg($pg['port']) . ' -U ' . escapeshellarg($pg['user']);
        $connName = null;
        try {
            $this->run($env . 'dropdb --force --if-exists ' . $base . ' ' . escapeshellarg($scratch));
            $this->run($env . 'createdb ' . $base . ' ' . escapeshellarg($scratch));
            exec($env . 'pg_restore --no-owner --no-privileges -d ' . escapeshellarg($scratch) . ' '
                . $base . ' ' . escapeshellarg($tmpDump) . ' 2>&1', $o, $rc);

            [$scratchConn, $connName] = $this->scratchConnection($pg, $scratch);
            $tables = (int)$scratchConn->execute(
                "SELECT count(*) AS n FROM information_schema.tables WHERE table_schema = 'core'",
            )->fetch('assoc')['n'];

            return ['ok' => $tables > 0, 'tables' => $tables, 'reason' => $tables > 0 ? null : 'Keine core-Tabellen im Restore.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'tables' => 0, 'reason' => $e->getMessage()];
        } finally {
            if ($connName !== null) {
                try {
                    ConnectionManager::get($connName)->getDriver()->disconnect();
                    ConnectionManager::drop($connName);
                } catch (\Throwable) {
                }
            }
            @exec($env . 'dropdb --force --if-exists ' . $base . ' ' . escapeshellarg($scratch) . ' 2>&1');
            @unlink($tmpDump);
        }
    }

    /** **Destruktive** Wiederherstellung einer gespeicherten Sicherung. */
    public function restore(string $id): void
    {
        $row = $this->get($id);
        if ($row === null) {
            throw new RuntimeException('Backup unbekannt.');
        }
        $this->restoreFromFile((string)$row['path']);
        $this->audit->log('backup.restore', 'backup', $id, ['component' => 'core']);
    }

    /**
     * **Destruktive** Wiederherstellung aus einer beliebigen Archivdatei
     * (Linux-/Windows-Pfad). Stellt DB (`pg_restore --clean`, **mit** Privilegien
     * → App-Rollen-GRANTs bleiben erhalten) + Datei-Stores wieder her.
     */
    public function restoreFromFile(string $zipPath): void
    {
        $zip = BackupPath::normalize($zipPath);
        if (!is_file($zip)) {
            throw new RuntimeException('Archivdatei nicht gefunden: ' . $zip);
        }
        $tmp = sys_get_temp_dir() . '/bkrestore_' . substr(md5($zip), 0, 10);
        @exec('rm -rf ' . escapeshellarg($tmp));
        if (!@mkdir($tmp, 0o775, true)) {
            throw new RuntimeException('Temp-Verzeichnis nicht anlegbar: ' . $tmp);
        }
        try {
            if (!$this->extractEntry($zip, 'database.dump', $tmp . '/database.dump')) {
                throw new RuntimeException('database.dump nicht im Archiv.');
            }
            $hasFiles = $this->extractEntry($zip, 'files.tar.gz', $tmp . '/files.tar.gz');

            $pg = $this->pg();
            $env = 'PGPASSWORD=' . escapeshellarg($pg['pass']) . ' ';
            $base = '-h ' . escapeshellarg($pg['host']) . ' -p ' . escapeshellarg($pg['port']) . ' -U ' . escapeshellarg($pg['user']);
            // KEIN --no-privileges: sonst gingen die GRANTs an die NOBYPASSRLS-
            // App-Rolle verloren (Laufzeit hätte keinen Tabellenzugriff mehr).
            exec($env . 'pg_restore --clean --if-exists --no-owner -d ' . escapeshellarg($pg['db'])
                . ' ' . $base . ' ' . escapeshellarg($tmp . '/database.dump') . ' 2>&1', $o, $rc);

            if ($hasFiles && (int)filesize($tmp . '/files.tar.gz') > 0) {
                $this->run('tar xzf ' . escapeshellarg($tmp . '/files.tar.gz') . ' -C ' . escapeshellarg(ROOT));
            }
        } finally {
            @exec('rm -rf ' . escapeshellarg($tmp));
        }
    }

    /** Behält die jüngsten $keep Sicherungen, löscht ältere. Anzahl gelöschter zurück. */
    public function prune(int $keep): int
    {
        if ($keep < 1) {
            return 0;
        }
        $old = array_slice($this->list(), $keep);
        foreach ($old as $b) {
            $this->delete((string)$b['id']);
        }

        return count($old);
    }

    public function delete(string $id): bool
    {
        $row = $this->get($id);
        if ($row === null) {
            return false;
        }
        $zip = (string)$row['path'];
        if (is_file($zip)) {
            @unlink($zip);
        }
        $this->conn()->execute('DELETE FROM backups WHERE id = :id', ['id' => $id]);
        $this->audit->log('backup.delete', 'backup', $id, ['component' => 'core']);

        return true;
    }

    // ---- intern --------------------------------------------------------------

    private function entrySha(ZipArchive $za, string $entry): string
    {
        $s = $za->getStream($entry);
        if ($s === false) {
            return '';
        }
        $ctx = hash_init('sha256');
        while (!feof($s)) {
            hash_update($ctx, (string)fread($s, 1 << 16));
        }
        fclose($s);

        return hash_final($ctx);
    }

    private function extractEntry(string $zip, string $entry, string $dest): bool
    {
        $za = new ZipArchive();
        if ($za->open($zip) !== true) {
            return false;
        }
        $s = $za->getStream($entry);
        if ($s === false) {
            $za->close();

            return false;
        }
        $out = fopen($dest, 'wb');
        if ($out === false) {
            fclose($s);
            $za->close();

            return false;
        }
        stream_copy_to_stream($s, $out);
        fclose($out);
        fclose($s);
        $za->close();

        return true;
    }

    /** @return array{0:\Cake\Database\Connection,1:string} */
    private function scratchConnection(array $pg, string $scratch): array
    {
        $name = 'scratch_' . substr(md5($scratch), 0, 8);
        if (ConnectionManager::getConfig($name) === null) {
            ConnectionManager::setConfig($name, [
                'className' => \Cake\Database\Connection::class,
                'driver' => \Cake\Database\Driver\Postgres::class,
                'host' => $pg['host'], 'port' => $pg['port'], 'username' => $pg['user'],
                'password' => $pg['pass'], 'database' => $scratch, 'encoding' => 'utf8',
                'timezone' => 'UTC', 'cacheMetadata' => false,
            ]);
        }
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get($name);

        return [$conn, $name];
    }

    private function lock(): bool
    {
        $row = $this->conn()->execute('SELECT pg_try_advisory_lock(:k) AS ok', ['k' => self::LIFECYCLE_LOCK])->fetch('assoc');

        return $row['ok'] === true || $row['ok'] === 't';
    }

    private function unlock(): void
    {
        $this->conn()->execute('SELECT pg_advisory_unlock(:k)', ['k' => self::LIFECYCLE_LOCK]);
    }
}
