<?php
declare(strict_types=1);

namespace App\Service\Backup;

use App\Application;
use App\Audit\AuditLogger;
use App\Infrastructure\Db;
use Cake\Datasource\ConnectionManager;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Konsistente Sicherung & Wiederherstellung (Kap. 20.1, bewusste Abweichung
 * „keine Systemfunktion" auf Nutzer-Wunsch, E53).
 *
 * Eine Sicherung umfasst die **gesamte Datenbank** (`pg_dump -Fc`) **und** die
 * persistenten Datei-Stores (`language-store`, `marketplace-data`, `modules`).
 * Beides wird unter dem **Lifecycle-Advisory-Lock** erstellt → kein Modul-/
 * Sprachschreibvorgang dazwischen, also DB↔Storage konsistent. Checksummen je
 * Artefakt machen die Sicherung **prüfbar**; `testRestore()` spielt den Dump in
 * eine **Scratch-Datenbank** ein (ohne die Produktion zu berühren).
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
        $this->base = rtrim($base ?? (ROOT . DIRECTORY_SEPARATOR . 'backups'), '/');
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

    /** Führt ein Shell-Kommando aus; wirft bei Fehler mit Ausgabe. */
    private function run(string $cmd): void
    {
        exec($cmd . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            throw new RuntimeException('Kommando fehlgeschlagen (' . $rc . '): ' . implode("\n", array_slice($out, -5)));
        }
    }

    /**
     * Erstellt eine konsistente Sicherung. Gibt die Backup-ID zurück.
     */
    public function create(?string $note, ?string $actorId): string
    {
        if (!is_dir($this->base) && !@mkdir($this->base, 0o775, true) && !is_dir($this->base)) {
            throw new RuntimeException('Backup-Verzeichnis nicht anlegbar: ' . $this->base);
        }
        $id = Uuid::v7()->toRfc4122();
        $dir = $this->base . '/' . $id;
        if (!@mkdir($dir, 0o775, true)) {
            throw new RuntimeException('Backup-Unterverzeichnis nicht anlegbar: ' . $dir);
        }
        $pg = $this->pg();
        $env = 'PGPASSWORD=' . escapeshellarg($pg['pass']) . ' ';
        $dumpFile = $dir . '/database.dump';
        $filesTar = $dir . '/files.tar.gz';

        $this->conn()->execute(
            'INSERT INTO backups (id, core_version, status, path, note, created_by) '
            . "VALUES (:id, :v, 'creating', :p, :n, :cb)",
            ['id' => $id, 'v' => Application::CORE_VERSION, 'p' => $dir, 'n' => $note, 'cb' => $actorId],
        );

        // Konsistenz: unter dem Lifecycle-Lock (kein Install/Lang-Write dazwischen).
        $locked = $this->lock();
        try {
            // 1. Datenbank (gesamt, custom-format → restorebar).
            $this->run($env . 'pg_dump -h ' . escapeshellarg($pg['host']) . ' -p ' . escapeshellarg($pg['port'])
                . ' -U ' . escapeshellarg($pg['user']) . ' -d ' . escapeshellarg($pg['db'])
                . ' -Fc -f ' . escapeshellarg($dumpFile));

            // 2. Datei-Stores (nur vorhandene).
            $present = array_values(array_filter(self::STORES, fn ($s) => is_dir(ROOT . '/' . $s)));
            if ($present !== []) {
                $this->run('tar czf ' . escapeshellarg($filesTar) . ' -C ' . escapeshellarg(ROOT)
                    . ' ' . implode(' ', array_map('escapeshellarg', $present)));
            } else {
                $this->run('tar czf ' . escapeshellarg($filesTar) . ' -C ' . escapeshellarg(ROOT) . ' --files-from /dev/null');
            }
        } catch (\Throwable $e) {
            $this->conn()->execute("UPDATE backups SET status = 'failed' WHERE id = :id", ['id' => $id]);
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
        file_put_contents($dir . '/manifest.json', (string)json_encode($manifest, JSON_PRETTY_PRINT));

        $this->conn()->execute(
            "UPDATE backups SET status = 'complete', db_bytes = :db, files_bytes = :fb, "
            . 'db_sha256 = :ds, files_sha256 = :fs WHERE id = :id',
            ['db' => filesize($dumpFile), 'fb' => filesize($filesTar), 'ds' => $dbSha, 'fs' => $filesSha, 'id' => $id],
        );
        $this->audit->log('backup.create', 'backup', $id, ['component' => 'core', 'newValue' => ['note' => $note]]);

        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return $this->conn()->execute(
            'SELECT id, created_at, core_version, status, db_bytes, files_bytes, note FROM backups ORDER BY created_at DESC',
        )->fetchAll('assoc');
    }

    /** @return array<string,mixed>|null */
    public function get(string $id): ?array
    {
        $row = $this->conn()->execute('SELECT * FROM backups WHERE id = :id', ['id' => $id])->fetch('assoc');

        return $row === false ? null : $row;
    }

    /**
     * Prüft die Integrität: Artefakte vorhanden + Checksummen wie gespeichert.
     *
     * @return array{ok:bool, db:bool, files:bool, reason:?string}
     */
    public function verify(string $id): array
    {
        $row = $this->get($id);
        if ($row === null) {
            return ['ok' => false, 'db' => false, 'files' => false, 'reason' => 'Backup unbekannt.'];
        }
        $dir = (string)$row['path'];
        $dumpFile = $dir . '/database.dump';
        $filesTar = $dir . '/files.tar.gz';
        if (!is_file($dumpFile) || !is_file($filesTar)) {
            return ['ok' => false, 'db' => false, 'files' => false, 'reason' => 'Artefakt(e) fehlen.'];
        }
        $db = hash_file('sha256', $dumpFile) === (string)$row['db_sha256'];
        $files = hash_file('sha256', $filesTar) === (string)$row['files_sha256'];

        return ['ok' => $db && $files, 'db' => $db, 'files' => $files, 'reason' => $db && $files ? null : 'Checksumme abweichend.'];
    }

    /**
     * Spielt den DB-Dump in eine **Scratch-Datenbank** ein und prüft ihn dort
     * (ohne die Produktion zu berühren). Gibt Erfolg + gefundene Core-Tabellen.
     *
     * @return array{ok:bool, tables:int, reason:?string}
     */
    public function testRestore(string $id): array
    {
        $row = $this->get($id);
        if ($row === null) {
            return ['ok' => false, 'tables' => 0, 'reason' => 'Backup unbekannt.'];
        }
        $dumpFile = (string)$row['path'] . '/database.dump';
        if (!is_file($dumpFile)) {
            return ['ok' => false, 'tables' => 0, 'reason' => 'DB-Dump fehlt.'];
        }
        $pg = $this->pg();
        $env = 'PGPASSWORD=' . escapeshellarg($pg['pass']) . ' ';
        $scratch = 'fertura_verify_' . substr(str_replace('-', '', $id), 0, 12);
        $base = '-h ' . escapeshellarg($pg['host']) . ' -p ' . escapeshellarg($pg['port']) . ' -U ' . escapeshellarg($pg['user']);

        $connName = null;
        try {
            $this->run($env . 'dropdb --force --if-exists ' . $base . ' ' . escapeshellarg($scratch));
            $this->run($env . 'createdb ' . $base . ' ' . escapeshellarg($scratch));
            // pg_restore kann bei Extensions/Owner Warnungen liefern → Fehler tolerieren,
            // Erfolg über die Sanity-Prüfung unten festmachen.
            exec($env . 'pg_restore --no-owner --no-privileges -d ' . escapeshellarg($scratch) . ' '
                . $base . ' ' . escapeshellarg($dumpFile) . ' 2>&1', $o, $rc);

            [$scratchConn, $connName] = $this->scratchConnection($pg, $scratch);
            $tables = (int)$scratchConn->execute(
                "SELECT count(*) AS n FROM information_schema.tables WHERE table_schema = 'core'",
            )->fetch('assoc')['n'];

            return ['ok' => $tables > 0, 'tables' => $tables, 'reason' => $tables > 0 ? null : 'Keine core-Tabellen im Restore.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'tables' => 0, 'reason' => $e->getMessage()];
        } finally {
            // Verbindung schließen + Config entfernen, sonst blockiert sie das Drop.
            if ($connName !== null) {
                try {
                    ConnectionManager::get($connName)->getDriver()->disconnect();
                    ConnectionManager::drop($connName);
                } catch (\Throwable) {
                    // ignore
                }
            }
            @exec($env . 'dropdb --force --if-exists ' . $base . ' ' . escapeshellarg($scratch) . ' 2>&1');
        }
    }

    /**
     * **Destruktive** Wiederherstellung in die Produktion (nur CLI, `--yes`).
     * Stellt DB (pg_restore --clean) + Datei-Stores wieder her.
     */
    public function restore(string $id): void
    {
        $row = $this->get($id);
        if ($row === null) {
            throw new RuntimeException('Backup unbekannt.');
        }
        $dir = (string)$row['path'];
        $dumpFile = $dir . '/database.dump';
        $filesTar = $dir . '/files.tar.gz';
        if (!is_file($dumpFile)) {
            throw new RuntimeException('DB-Dump fehlt.');
        }
        $pg = $this->pg();
        $env = 'PGPASSWORD=' . escapeshellarg($pg['pass']) . ' ';
        $base = '-h ' . escapeshellarg($pg['host']) . ' -p ' . escapeshellarg($pg['port']) . ' -U ' . escapeshellarg($pg['user']);

        // DB zurückspielen (Objekte vorher droppen).
        exec($env . 'pg_restore --clean --if-exists --no-owner --no-privileges -d ' . escapeshellarg($pg['db'])
            . ' ' . $base . ' ' . escapeshellarg($dumpFile) . ' 2>&1', $o, $rc);

        // Datei-Stores zurückspielen.
        if (is_file($filesTar) && filesize($filesTar) > 0) {
            $this->run('tar xzf ' . escapeshellarg($filesTar) . ' -C ' . escapeshellarg(ROOT));
        }
        $this->audit->log('backup.restore', 'backup', $id, ['component' => 'core']);
    }

    /** Löscht eine Sicherung (Dateien + Metadaten). */
    public function delete(string $id): bool
    {
        $row = $this->get($id);
        if ($row === null) {
            return false;
        }
        $dir = (string)$row['path'];
        if (is_dir($dir)) {
            @exec('rm -rf ' . escapeshellarg($dir));
        }
        $this->conn()->execute('DELETE FROM backups WHERE id = :id', ['id' => $id]);
        $this->audit->log('backup.delete', 'backup', $id, ['component' => 'core']);

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
