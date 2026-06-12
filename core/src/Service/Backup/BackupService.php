<?php
declare(strict_types=1);

namespace App\Service\Backup;

use App\Application;
use App\Audit\AuditLogger;
use App\Infrastructure\Db;
use App\Service\Mail\MailService;
use App\Service\Settings\SettingsManager;
use App\Service\System\MaintenanceMode;
use Cake\Database\Connection;
use Cake\Database\Driver\Postgres;
use Cake\Datasource\ConnectionManager;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Throwable;
use ZipArchive;
use function Cake\Core\env;

/**
 * Consistent data backup & restore (ch. 20.1.2, E53/E55/E56).
 *
 * A backup covers the **entire database** (`pg_dump -Fc`) **and** the persistent
 * file stores and is stored as **a single ZIP archive** whose name carries the
 * **UTC timestamp** (`<YYYYMMDD-HHMMSS>_<id>.zip`). Creation runs under the
 * **lifecycle lock** (keeping DB and storage consistent); each artifact gets a
 * SHA-256.
 *
 * Hardening (E56): optional **AES-256 encryption** of the archive contents
 * (`backup.password`, segregation of duty), **verification before completion**
 * (integrity always, probe-restore optional), and an **operations log**
 * (`backup_log`) covering both backups *and* restores.
 */
class BackupService
{
    private const LIFECYCLE_LOCK = 778899001;

    /** @var list<string> Persistent file stores relative to ROOT. */
    private const STORES = ['language-store', 'marketplace-data', 'modules'];

    private string $base;
    private string $source = 'cli';
    private ?string $actor = null;

    public function __construct(?string $base = null, private ?AuditLogger $audit = null)
    {
        if ($base === null) {
            try {
                $base = (string)(new SettingsManager())->get('core', 'backup.path', ROOT . '/backups');
            } catch (Throwable) {
                $base = ROOT . '/backups';
            }
            if (trim($base) === '') {
                $base = ROOT . '/backups';
            }
        }
        $this->base = BackupPath::normalize($base);
        $this->audit ??= new AuditLogger();
    }

    /** Sets the origin (cli|gui|scheduler) and actor for the log. */
    public function context(string $source, ?string $actor = null): self
    {
        $this->source = $source;
        $this->actor = $actor;

        return $this;
    }

    public function base(): string
    {
        return $this->base;
    }

    /** Whether backups are encrypted (password set via env/secret/DB setting). */
    public function encryptionEnabled(): bool
    {
        return $this->password() !== '';
    }

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /**
     * Backup password. Precedence chosen for **disaster-recovery viability**:
     *   1. Secret file `BACKUP_PASSWORD_FILE` (out-of-band, not in the backup)
     *   2. Environment variable `BACKUP_PASSWORD`
     *   3. DB setting `backup.password` (convenience fallback)
     *
     * **Important:** the DB setting itself lives in the database dump — a backup
     * encrypted with it could not be decrypted on a fresh system (chicken-and-egg).
     * For DR the password must therefore be provided via env/secret (1./2.), not
     * via the DB setting.
     */
    private function password(): string
    {
        $file = (string)env('BACKUP_PASSWORD_FILE');
        if ($file !== '' && is_file($file)) {
            return trim((string)file_get_contents($file));
        }
        $env = (string)env('BACKUP_PASSWORD');
        if ($env !== '') {
            return $env;
        }
        try {
            return (string)(new SettingsManager())->get('core', 'backup.password', '');
        } catch (Throwable) {
            return '';
        }
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
     * Evaluates the output of a `pg_restore` run and throws on a **genuine**
     * error. `pg_restore` deliberately runs here without `--exit-on-error` (a
     * restore should run as completely as possible), yet it still signals
     * harmless situations via exit code/notices: `--clean --if-exists` produces
     * "… does not exist, skipping" notices and possibly a final "errors ignored
     * on restore: N" — both tolerable. Genuine `pg_restore: error:`/`ERROR:`
     * lines are not: they must not (as previously, when the exit code was never
     * checked) be swallowed and let a half-restored DB pass as "ok" (M6/B1).
     *
     * @param list<string> $out Collected (stdout+stderr) output of the run.
     */
    private function assertRestoreOk(array $out, int $rc, string $context): void
    {
        $errors = [];
        foreach ($out as $line) {
            if ($this->isIgnorableRestoreLine($line)) {
                continue;
            }
            if (
                stripos($line, 'pg_restore: error:') !== false
                || preg_match('/(?<![A-Za-z])ERROR:\s/', $line) === 1
            ) {
                $errors[] = trim($line);
            }
        }
        if ($errors !== []) {
            throw new RuntimeException(
                $context . ' meldete Fehler: ' . implode(' | ', array_slice($errors, 0, 5)),
            );
        }
        // No recognizable error text but exit code != 0: pure
        // "does not exist, skipping" notices from a `--clean` run are tolerable
        // (exactly the false alarm a plain exit-code check would raise).
        // Anything else — unknown output, or no output at all with rc != 0
        // (pg_restore not found, connection dropped) — is a structural problem
        // and must not silently count as success.
        if ($rc !== 0) {
            $unexpected = array_values(array_filter($out, fn($l) => !$this->isIgnorableRestoreLine($l)));
            if ($unexpected !== [] || $out === []) {
                throw new RuntimeException(
                    $context . ' fehlgeschlagen (rc=' . $rc . '): '
                    . ($out === [] ? '(keine Ausgabe)' : implode("\n", array_slice($unexpected, 0, 5))),
                );
            }
        }
    }

    /** Tolerable (non-fatal) output line of a `pg_restore --clean --if-exists` run. */
    private function isIgnorableRestoreLine(string $line): bool
    {
        if (trim($line) === '') {
            return true;
        }
        foreach (['does not exist, skipping', 'errors ignored on restore', 'pg_restore: warning:'] as $needle) {
            if (stripos($line, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Creates a consistent, verified (possibly encrypted) backup. Returns the
     * backup ID. `$targetDir` optionally overrides the storage location.
     */
    public function create(?string $note, ?string $actorId, ?string $targetDir = null): string
    {
        $this->actor ??= $actorId;
        $dir = $targetDir !== null && trim($targetDir) !== ''
            ? BackupPath::assertUsable($targetDir)
            : $this->base;
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('Backup-Verzeichnis nicht anlegbar/erreichbar: ' . $dir);
        }
        // Pre-flight BEFORE anything else; a failure is logged and alerted.
        try {
            $this->preflightSpace($dir);
        } catch (Throwable $e) {
            $this->log('create', null, 'error', $e->getMessage());
            $this->alertFailure($e->getMessage());
            throw $e;
        }

        $id = Uuid::v7()->toRfc4122();
        $ts = gmdate('Ymd-His');
        $zip = $dir . '/' . $ts . '_' . $id . '.zip';
        $work = $dir . '/' . $id . '.work';
        if (!@mkdir($work, 0o775, true)) {
            throw new RuntimeException('Arbeitsverzeichnis nicht anlegbar: ' . $work);
        }
        $dumpFile = $work . '/database.dump';
        $filesTar = $work . '/files.tar.gz';
        $pg = $this->pg();
        $env = 'PGPASSWORD=' . escapeshellarg($pg['pass']) . ' ';
        $pw = $this->password();
        $encrypted = $pw !== '';

        $this->conn()->execute(
            'INSERT INTO backups (id, core_version, status, path, note, created_by, encrypted) '
            . "VALUES (:id, :v, 'creating', :p, :n, :cb, :enc)",
            ['id' => $id, 'v' => Application::CORE_VERSION, 'p' => $zip, 'n' => $note, 'cb' => $actorId, 'enc' => $encrypted ? 'true' : 'false'],
        );

        try {
            // The lifecycle lock is mandatory, not "nice to have": without it a
            // backup would be a DB-vs-storage-inconsistent snapshot the moment a
            // concurrent lifecycle/update/restore operation changes data or file
            // stores between pg_dump and tar. If we cannot acquire it (another
            // operation holds it), we abort loudly instead of silently proceeding
            // without the lock (B2).
            if (!$this->lock()) {
                throw new RuntimeException(
                    'Backup nicht möglich: Der Lifecycle-Lock wird von einer anderen Operation '
                    . '(Update/Restore/Backup) gehalten — ein konsistenter Snapshot ist gerade nicht '
                    . 'erstellbar. Bitte später erneut versuchen.',
                );
            }
            try {
                $this->run($env . 'pg_dump -h ' . escapeshellarg($pg['host']) . ' -p ' . escapeshellarg($pg['port'])
                    . ' -U ' . escapeshellarg($pg['user']) . ' -d ' . escapeshellarg($pg['db'])
                    . ' -Fc -f ' . escapeshellarg($dumpFile));
                $present = array_values(array_filter(self::STORES, fn($s) => is_dir(ROOT . '/' . $s)));
                if ($present !== []) {
                    $this->run('tar czf ' . escapeshellarg($filesTar) . ' -C ' . escapeshellarg(ROOT)
                        . ' ' . implode(' ', array_map('escapeshellarg', $present)));
                } else {
                    $this->run('tar czf ' . escapeshellarg($filesTar) . ' -C ' . escapeshellarg(ROOT) . ' --files-from /dev/null');
                }
            } finally {
                $this->unlock();
            }

            $dbSha = hash_file('sha256', $dumpFile) ?: '';
            $filesSha = hash_file('sha256', $filesTar) ?: '';
            $manifest = [
                'id' => $id, 'core_version' => Application::CORE_VERSION, 'created_utc' => gmdate('c'),
                'encrypted' => $encrypted,
                'database' => ['file' => 'database.dump', 'bytes' => filesize($dumpFile), 'sha256' => $dbSha],
                'files' => ['file' => 'files.tar.gz', 'bytes' => filesize($filesTar), 'sha256' => $filesSha, 'stores' => self::STORES],
            ];

            $this->buildZip($zip, $dumpFile, $filesTar, (string)json_encode($manifest, JSON_PRETTY_PRINT), $pw);
            @exec('rm -rf ' . escapeshellarg($work));

            // --- Verification BEFORE completion (E56) ---
            if (!$this->archiveIntact($zip, $dbSha, $filesSha, $pw)) {
                throw new RuntimeException('Integritätsprüfung des Archivs fehlgeschlagen.');
            }
            $deep = (bool)(new SettingsManager())->get('core', 'backup.verify_on_create', true);
            if ($deep) {
                $probe = $this->probeRestore($zip, $pw);
                if (!$probe['ok']) {
                    throw new RuntimeException('Probe-Restore fehlgeschlagen: ' . ($probe['reason'] ?? ''));
                }
            }
        } catch (Throwable $e) {
            @exec('rm -rf ' . escapeshellarg($work));
            @unlink($zip);
            $this->conn()->execute("UPDATE backups SET status = 'failed' WHERE id = :id", ['id' => $id]);
            $this->log('create', $id, 'error', $e->getMessage());
            $this->alertFailure($e->getMessage());
            throw $e;
        }

        $this->conn()->execute(
            "UPDATE backups SET status = 'complete', verified = true, db_bytes = :db, files_bytes = :fb, "
            . 'db_sha256 = :ds, files_sha256 = :fs WHERE id = :id',
            ['db' => $manifest['database']['bytes'], 'fb' => $manifest['files']['bytes'], 'ds' => $dbSha, 'fs' => $filesSha, 'id' => $id],
        );
        $this->audit->log('backup.create', 'backup', $id, ['component' => 'core', 'newValue' => ['note' => $note, 'path' => $zip, 'encrypted' => $encrypted]]);
        $this->log('create', $id, 'ok', ($encrypted ? 'verschlüsselt' : 'unverschlüsselt') . ($deep ? ', probe-restore ok' : ''));

        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return array_values($this->conn()->execute(
            'SELECT id, created_at, core_version, status, db_bytes, files_bytes, path, note, encrypted, verified '
            . 'FROM backups ORDER BY created_at DESC',
        )->fetchAll('assoc'));
    }

    /**
     * Operations log (backups + restores), newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function logEntries(int $limit = 100): array
    {
        return array_values($this->conn()->execute(
            'SELECT l.occurred_at, l.operation, l.backup_id, l.source, l.result, l.message, u.username AS actor '
            . 'FROM backup_log l LEFT JOIN users u ON u.id = l.actor_user_id '
            . 'ORDER BY l.occurred_at DESC LIMIT :lim',
            ['lim' => $limit],
        )->fetchAll('assoc'));
    }

    /** @return array<string,mixed>|null */
    public function get(string $id): ?array
    {
        // UUID guard: the ID comes from URL/CLI; a malformed value would throw in
        // the PG uuid comparison (22P02) -> 500 instead of a clean "unknown".
        // Fully qualified on purpose: the short `Uuid` here is Symfony's (used for
        // v7 generation); this guard needs App's strict, `\z`-anchored validator.
        if (!\App\Infrastructure\Uuid::isValid($id)) {
            return null;
        }
        $row = $this->conn()->execute('SELECT * FROM backups WHERE id = :id', ['id' => $id])->fetch('assoc');

        return $row === false ? null : $row;
    }

    /**
     * Checks integrity (ZIP readable + inner checksums as stored).
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
        $pw = $this->bool($row['encrypted']) ? $this->password() : '';
        $db = $this->entrySha($zip, 'database.dump', $pw) === (string)$row['db_sha256'];
        $files = $this->entrySha($zip, 'files.tar.gz', $pw) === (string)$row['files_sha256'];

        return ['ok' => $db && $files, 'db' => $db, 'files' => $files, 'reason' => $db && $files ? null : 'Prüfsumme abweichend (ggf. falsches Passwort).'];
    }

    /**
     * Probe-restores a stored backup into a scratch DB.
     *
     * @return array{ok:bool, tables:int, reason:?string}
     */
    public function testRestore(string $id): array
    {
        $row = $this->get($id);
        if ($row === null) {
            return ['ok' => false, 'tables' => 0, 'reason' => 'Backup unbekannt.'];
        }
        $pw = $this->bool($row['encrypted']) ? $this->password() : '';

        return $this->probeRestore((string)$row['path'], $pw);
    }

    /** **Destructive** restore of a stored backup. */
    public function restore(string $id): void
    {
        $row = $this->get($id);
        if ($row === null) {
            throw new RuntimeException('Backup unbekannt.');
        }
        $pw = $this->bool($row['encrypted']) ? $this->password() : '';
        // Controlled cutover: maintenance mode (503) for the duration of the
        // destructive restore, so no request hits a half-restored DB
        // (ch. 20.1.2). The file flag survives the DB restore.
        $engaged = MaintenanceMode::engage('restore');
        try {
            $this->restoreArchive((string)$row['path'], $pw);
        } catch (Throwable $e) {
            $this->log('restore', $id, 'error', $e->getMessage());
            throw $e;
        } finally {
            if ($engaged) {
                MaintenanceMode::release();
            }
        }
        $this->audit->log('backup.restore', 'backup', $id, ['component' => 'core']);
        $this->log('restore', $id, 'ok', null);
    }

    /**
     * **Destructive** restore from an arbitrary archive file (Linux/Windows
     * path). `$password` overrides the configured password.
     */
    public function restoreFromFile(string $zipPath, ?string $password = null): void
    {
        $pw = $password ?? $this->password();
        $engaged = MaintenanceMode::engage('restore');
        try {
            $this->restoreArchive(BackupPath::normalize($zipPath), $pw);
        } catch (Throwable $e) {
            $this->log('restore_from', null, 'error', $e->getMessage() . ' (' . $zipPath . ')');
            throw $e;
        } finally {
            if ($engaged) {
                MaintenanceMode::release();
            }
        }
        $this->log('restore_from', null, 'ok', $zipPath);
    }

    /** Keeps the most recent $keep backups and deletes older ones. */
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

    /** Deletes successful backups older than $days days. */
    public function pruneByAge(int $days): int
    {
        if ($days < 1) {
            return 0;
        }
        $rows = $this->conn()->execute(
            "SELECT id FROM backups WHERE status = 'complete' AND created_at < now() - (:d || ' days')::interval",
            ['d' => (string)$days],
        )->fetchAll('assoc');
        foreach ($rows as $r) {
            $this->delete((string)$r['id']);
        }

        return count($rows);
    }

    /** Logs an archive download (data export). */
    public function logDownload(string $id): void
    {
        $this->log('download', $id, 'ok', null);
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
        $this->log('delete', $id, 'ok', null);

        return true;
    }

    // ---- internal ------------------------------------------------------------

    private function buildZip(string $zip, string $dump, string $tar, string $manifestJson, string $pw): void
    {
        // Fail-closed: if a backup password is set but libzip lacks AES-256,
        // `setPassword()` alone would encrypt NOTHING — the archive would be in
        // plaintext while DB/manifest/audit record it as "encrypted". Better to
        // abort than to store a seemingly encrypted plaintext backup (which
        // contains the entire DB dump including all other secrets).
        if ($pw !== '' && !defined('ZipArchive::EM_AES_256')) {
            throw new RuntimeException(
                'Backup-Verschlüsselung verlangt (backup.password gesetzt), aber libzip ohne '
                . 'AES-256-Support — Abbruch statt unverschlüsselter Ablage.',
            );
        }
        $za = new ZipArchive();
        if ($za->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP nicht erstellbar: ' . $zip);
        }
        if ($pw !== '') {
            $za->setPassword($pw);
        }
        $za->addFile($dump, 'database.dump');
        $za->addFile($tar, 'files.tar.gz');
        $za->addFromString('manifest.json', $manifestJson);
        if ($pw !== '') {
            foreach (['database.dump', 'files.tar.gz', 'manifest.json'] as $e) {
                // Every entry MUST be encrypted — if that fails, abort.
                if (!$za->setEncryptionName($e, ZipArchive::EM_AES_256)) {
                    $za->close();
                    @unlink($zip);

                    throw new RuntimeException("Backup-Eintrag '$e' nicht verschlüsselbar — Abbruch.");
                }
            }
        }
        $za->close();
    }

    /**
     * Throws if the tar.gz contains unsafe entries (absolute paths or `..`
     * traversal). Checked before extracting into ROOT (Zip-Slip protection).
     */
    private function assertSafeTar(string $tarGz): void
    {
        $entries = [];
        @exec('tar tzf ' . escapeshellarg($tarGz) . ' 2>/dev/null', $entries, $rc);
        if ($rc !== 0) {
            throw new RuntimeException('Backup-Archiv (files.tar.gz) nicht lesbar — Wiederherstellung abgebrochen.');
        }
        foreach ($entries as $entry) {
            $entry = (string)$entry;
            if ($entry === '') {
                continue;
            }
            if (str_starts_with($entry, '/') || preg_match('#(^|/)\.\.(/|$)#', $entry) === 1) {
                throw new RuntimeException(
                    'Backup-Archiv enthält unsicheren Pfad — Wiederherstellung abgebrochen: ' . $entry,
                );
            }
        }
    }

    private function archiveIntact(string $zip, string $dbSha, string $filesSha, string $pw): bool
    {
        return $this->entrySha($zip, 'database.dump', $pw) === $dbSha
            && $this->entrySha($zip, 'files.tar.gz', $pw) === $filesSha;
    }

    /**
     * Probe-restores an archive file into a scratch DB.
     *
     * @return array{ok:bool, tables:int, reason:?string}
     */
    private function probeRestore(string $zip, string $pw): array
    {
        $tmpDump = sys_get_temp_dir() . '/bk_' . substr(md5($zip), 0, 10) . '.dump';
        if (!$this->extractEntry($zip, 'database.dump', $tmpDump, $pw)) {
            return ['ok' => false, 'tables' => 0, 'reason' => 'DB-Dump nicht extrahierbar (ggf. falsches Passwort).'];
        }
        $pg = $this->pg();
        $env = 'PGPASSWORD=' . escapeshellarg($pg['pass']) . ' ';
        $scratch = 'fertura_verify_' . substr(md5($zip . microtime()), 0, 12);
        $base = '-h ' . escapeshellarg($pg['host']) . ' -p ' . escapeshellarg($pg['port']) . ' -U ' . escapeshellarg($pg['user']);
        $connName = null;
        try {
            $this->run($env . 'dropdb --force --if-exists ' . $base . ' ' . escapeshellarg($scratch));
            $this->run($env . 'createdb ' . $base . ' ' . escapeshellarg($scratch));
            exec($env . 'pg_restore --no-owner --no-privileges -d ' . escapeshellarg($scratch) . ' '
                . $base . ' ' . escapeshellarg($tmpDump) . ' 2>&1', $o, $rc);
            $this->assertRestoreOk($o, $rc, 'Probe-Restore (pg_restore)');
            [$scratchConn, $connName] = $this->scratchConnection($pg, $scratch);
            $tables = (int)$scratchConn->execute(
                "SELECT count(*) AS n FROM information_schema.tables WHERE table_schema = 'core'",
            )->fetch('assoc')['n'];

            return ['ok' => $tables > 0, 'tables' => $tables, 'reason' => $tables > 0 ? null : 'Keine core-Tabellen im Restore.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'tables' => 0, 'reason' => $e->getMessage()];
        } finally {
            if ($connName !== null) {
                try {
                    ConnectionManager::get($connName)->getDriver()->disconnect();
                    ConnectionManager::drop($connName);
                } catch (Throwable) {
                }
            }
            @exec($env . 'dropdb --force --if-exists ' . $base . ' ' . escapeshellarg($scratch) . ' 2>&1');
            @unlink($tmpDump);
        }
    }

    private function restoreArchive(string $zip, string $pw): void
    {
        if (!is_file($zip)) {
            throw new RuntimeException('Archivdatei nicht gefunden: ' . $zip);
        }
        $tmp = sys_get_temp_dir() . '/bkrestore_' . substr(md5($zip), 0, 10);
        @exec('rm -rf ' . escapeshellarg($tmp));
        if (!@mkdir($tmp, 0o775, true)) {
            throw new RuntimeException('Temp-Verzeichnis nicht anlegbar: ' . $tmp);
        }
        try {
            if (!$this->extractEntry($zip, 'database.dump', $tmp . '/database.dump', $pw)) {
                throw new RuntimeException('database.dump nicht extrahierbar (ggf. falsches Passwort).');
            }
            $hasFiles = $this->extractEntry($zip, 'files.tar.gz', $tmp . '/files.tar.gz', $pw);
            $pg = $this->pg();
            $env = 'PGPASSWORD=' . escapeshellarg($pg['pass']) . ' ';
            $base = '-h ' . escapeshellarg($pg['host']) . ' -p ' . escapeshellarg($pg['port']) . ' -U ' . escapeshellarg($pg['user']);
            exec($env . 'pg_restore --clean --if-exists --no-owner -d ' . escapeshellarg($pg['db'])
                . ' ' . $base . ' ' . escapeshellarg($tmp . '/database.dump') . ' 2>&1', $o, $rc);
            // Check exit code + output BEFORE the file stores are extracted and
            // (via restore()) maintenance mode is released again — otherwise a
            // half-restored DB would silently come back online (M6/B1).
            $this->assertRestoreOk($o, $rc, 'Wiederherstellung (pg_restore)');
            if ($hasFiles && (int)filesize($tmp . '/files.tar.gz') > 0) {
                // Path-traversal protection: check the (potentially
                // operator-supplied) archive BEFORE extracting into ROOT — no
                // absolute paths, no `..`. Prevents a tampered archive from
                // overwriting files outside the stores (e.g. config/webroot)
                // (Zip-Slip).
                $this->assertSafeTar($tmp . '/files.tar.gz');
                $this->run('tar xzf ' . escapeshellarg($tmp . '/files.tar.gz')
                    . ' -C ' . escapeshellarg(ROOT) . ' --no-same-owner');
            }
        } finally {
            @exec('rm -rf ' . escapeshellarg($tmp));
        }
    }

    private function entrySha(string $zip, string $entry, string $pw): string
    {
        $za = new ZipArchive();
        if ($za->open($zip) !== true) {
            return '';
        }
        if ($pw !== '') {
            $za->setPassword($pw);
        }
        $s = $za->getStream($entry);
        if ($s === false) {
            $za->close();

            return '';
        }
        $ctx = hash_init('sha256');
        while (!feof($s)) {
            hash_update($ctx, (string)fread($s, 1 << 16));
        }
        fclose($s);
        $za->close();

        return hash_final($ctx);
    }

    private function extractEntry(string $zip, string $entry, string $dest, string $pw): bool
    {
        $za = new ZipArchive();
        if ($za->open($zip) !== true) {
            return false;
        }
        if ($pw !== '') {
            $za->setPassword($pw);
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
        // With AES + a wrong password, getStream may yield empty/corrupt data.
        return filesize($dest) > 0;
    }

    private function log(string $operation, ?string $backupId, string $result, ?string $message): void
    {
        try {
            $this->conn()->execute(
                'INSERT INTO backup_log (operation, backup_id, source, actor_user_id, result, message) '
                . 'VALUES (:op, :bid, :src, :actor, :res, :msg)',
                ['op' => $operation, 'bid' => $backupId, 'src' => $this->source, 'actor' => $this->actor, 'res' => $result, 'msg' => $message],
            );
        } catch (Throwable) {
            // Logging must not make the business action fail.
        }
    }

    private function bool(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === '1' || $v === 1;
    }

    /** @return array{0:\Cake\Database\Connection,1:string} */
    private function scratchConnection(array $pg, string $scratch): array
    {
        $name = 'scratch_' . substr(md5($scratch), 0, 8);
        if (ConnectionManager::getConfig($name) === null) {
            ConnectionManager::setConfig($name, [
                'className' => Connection::class,
                'driver' => Postgres::class,
                'host' => $pg['host'], 'port' => $pg['port'], 'username' => $pg['user'],
                'password' => $pg['pass'], 'database' => $scratch, 'encoding' => 'utf8',
                'timezone' => 'UTC', 'cacheMetadata' => false,
            ]);
        }
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get($name);

        return [$conn, $name];
    }

    /** Pre-flight: aborts BEFORE the dump if the target location has too little free space. */
    private function preflightSpace(string $dir): void
    {
        $free = @disk_free_space($dir);
        if ($free === false) {
            return; // cannot be determined → do not block
        }
        $minFree = (int)(new SettingsManager())->get('core', 'backup.min_free_mb', 500) * 1024 * 1024;
        $required = max($minFree, (int)($this->estimatedSize() * 1.1));
        if ($free < $required) {
            throw new RuntimeException(sprintf(
                'Nicht genug freier Speicher in %s: frei ~%d MB, benötigt ~%d MB.',
                $dir,
                (int)($free / 1048576),
                (int)($required / 1048576),
            ));
        }
    }

    /** Estimated backup size (DB size + file stores), as an upper bound. */
    private function estimatedSize(): int
    {
        $size = 0;
        try {
            $size += (int)$this->conn()->execute('SELECT pg_database_size(current_database()) AS s')->fetch('assoc')['s'];
        } catch (Throwable) {
        }
        foreach (self::STORES as $s) {
            $p = ROOT . '/' . $s;
            if (!is_dir($p)) {
                continue;
            }
            $out = [];
            @exec('du -sb ' . escapeshellarg($p) . ' 2>/dev/null', $out);
            if ($out !== []) {
                $size += (int)strtok($out[0], "\t ");
            }
        }

        return $size;
    }

    /** Sends an alert on backup failure if a recipient is configured. */
    private function alertFailure(string $message): void
    {
        try {
            $to = trim((string)(new SettingsManager())->get('core', 'backup.alert_email', ''));
            if ($to === '') {
                return;
            }
            (new MailService())->notify(
                $to,
                __('mail.backup_failed.subject'),
                __('mail.backup_failed.body', gmdate('c'), $this->source, $message),
            );
        } catch (Throwable) {
            // The alert itself must not trigger anything further.
        }
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
