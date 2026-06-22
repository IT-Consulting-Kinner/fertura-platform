<?php
declare(strict_types=1);

namespace App\Service\Backup;

use App\Audit\AuditLogger;
use App\Infrastructure\Db;
use App\Service\Settings\SettingsManager;
use App\Service\Storage\StorageManager;
use App\Service\Storage\TenantStorage;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Per-tenant data backup (tenant-backup design, Increment 6a — export half).
 *
 * Backs up the data of ONE tenant — the rows `WHERE tenant_id = :t` across the
 * tenant-scoped core tables — as a logical, FK-ordered row export (NDJSON per
 * table + manifest), packed into a ZIP (AES-256 when a backup password is set,
 * fail-closed). This is the tenant-facing counterpart to the operator-only full-DB
 * {@see BackupService}; the destructive cross-tenant DR restore stays CLI-only in
 * BackupService. The scoped RESTORE is Increment 6b; module-schema rows are 6d;
 * per-tenant files are 6c.
 *
 * The export runs in the calling tenant admin's request context, so RLS already
 * scopes reads to that tenant; the explicit `WHERE tenant_id = :t` is belt to that
 * brace and also covers the non-RLS tenant tables (users, settings, search_index,
 * embeddings).
 */
class TenantBackupService
{
    /**
     * Tenant-scoped core tables in FK-dependency order (parents first). The flag is
     * `restorable`: `false` tables are captured for completeness but never written
     * back by the scoped restore — `users` (identity: referenced by tokens/sessions/
     * audit actor; a later careful step) and `audit_log` (append-only revision
     * history; a restore appends a `tenant.restore` event instead of overwriting).
     *
     * @var list<array{0:string,1:bool}>
     */
    private const TABLES = [
        ['users', false],
        ['groups', true],
        ['groups_users', true],
        ['group_resource_permissions', true],
        ['tenant_modules', true],
        ['settings', true],
        ['automation_rules', true],
        ['workflow_definitions', true],
        ['workflow_instances', true],
        ['notifications', true],
        ['notification_prefs', true],
        ['webhook_subscriptions', true],
        ['webhook_deliveries', true],
        ['search_index', true],
        ['embeddings', true],
        ['audit_log', false],
    ];

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /** The request's tenant from the RLS context; '' when unset (fail-closed). */
    private function currentTenantId(): string
    {
        $row = $this->conn()->execute('SELECT core.current_tenant() AS t')->fetch('assoc');

        return $row !== false && $row['t'] !== null ? (string)$row['t'] : '';
    }

    /**
     * Backup password (AES-256 of the archive). Same precedence as the system
     * {@see BackupService} so a deployment configures encryption once: secret file
     * `BACKUP_PASSWORD_FILE`, then env `BACKUP_PASSWORD`, then the `backup.password`
     * setting. Empty = no encryption.
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

    /** Directory the tenant's backup archives live in (created on demand). */
    private function tenantDir(string $tenantId): string
    {
        $root = (string)((new SettingsManager())->get('core', 'backup.path', null)
            ?: ROOT . DIRECTORY_SEPARATOR . 'backups');
        $dir = $root . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . $tenantId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * Creates a per-tenant data backup for the current request tenant and returns
     * its `tenant_backups` id. Exports each tenant-scoped table to NDJSON, writes a
     * manifest, packs the ZIP (encrypted when a password is set) and records the
     * metadata row.
     */
    public function create(?string $note, ?string $actorId): string
    {
        $tenantId = $this->currentTenantId();
        if ($tenantId === '') {
            throw new RuntimeException(__('flash.tenant_backup.no_tenant'));
        }
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zztb_' . bin2hex(random_bytes(6));
        if (!@mkdir($tmp, 0700, true)) {
            throw new RuntimeException('Temp-Verzeichnis nicht erstellbar.');
        }
        $zipPath = null;
        try {
            $rowCounts = $this->exportTables($tenantId, $tmp);
            $fileCount = $this->stageTenantFiles($tenantId, $tmp . DIRECTORY_SEPARATOR . 'files');
            $manifest = (string)json_encode([
                'format' => 'fertura-tenant-backup/1',
                'tenant_id' => $tenantId,
                'created_at' => date('c'),
                'tables' => array_map(static fn(array $t): string => $t[0], self::TABLES),
                'row_counts' => $rowCounts,
                'file_count' => $fileCount,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            file_put_contents($tmp . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);

            $pw = $this->password();
            $filename = 'tenant_' . substr($tenantId, 0, 8) . '_' . date('Ymd-His')
                . '_' . bin2hex(random_bytes(3)) . '.zip';
            $zipPath = $this->tenantDir($tenantId) . DIRECTORY_SEPARATOR . $filename;
            $this->buildZip($zipPath, $tmp, $pw);

            $bytes = (int)filesize($zipPath);
            $sha = (string)hash_file('sha256', $zipPath);
            $row = $this->conn()->execute(
                'INSERT INTO tenant_backups '
                . '(tenant_id, filename, storage_path, bytes, sha256, encrypted, row_counts, note, created_by) '
                . 'VALUES (:t, :f, :p, :b, :s, :e, CAST(:rc AS jsonb), :n, :cb) RETURNING id',
                [
                    't' => $tenantId, 'f' => $filename, 'p' => $zipPath, 'b' => $bytes, 's' => $sha,
                    'e' => $pw !== '' ? 'true' : 'false', 'rc' => (string)json_encode($rowCounts),
                    'n' => $note !== null && $note !== '' ? $note : null, 'cb' => $actorId,
                ],
            )->fetch('assoc');

            return (string)$row['id'];
        } catch (Throwable $e) {
            // The archive was built but never recorded (e.g. the metadata INSERT
            // failed) — remove it so no dangling, unlisted backup file is left behind.
            if ($zipPath !== null && is_file($zipPath)) {
                @unlink($zipPath);
            }

            throw $e;
        } finally {
            $this->rrmdir($tmp);
        }
    }

    /**
     * Exports each tenant-scoped table's rows (`WHERE tenant_id = :t`) to an NDJSON
     * file in $dir, returning the per-table row counts.
     *
     * @return array<string,int>
     */
    private function exportTables(string $tenantId, string $dir): array
    {
        $counts = [];
        foreach ($this->backupTables() as $spec) {
            $cols = $this->insertableColumns($spec['schema'], $spec['table']);
            $select = $cols === []
                ? '*'
                : implode(', ', array_map(static fn(string $c): string => '"' . $c . '"', $cols));
            $rows = $this->conn()->execute(
                "SELECT $select FROM {$spec['sql']} WHERE tenant_id = :t",
                ['t' => $tenantId],
            )->fetchAll('assoc');
            $ndjson = '';
            foreach ($rows as $r) {
                $ndjson .= (string)json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
            }
            file_put_contents($dir . DIRECTORY_SEPARATOR . $spec['key'] . '.ndjson', $ndjson);
            $counts[$spec['key']] = count($rows);
        }

        return $counts;
    }

    /**
     * The INSERT-able columns of a table (excludes GENERATED ALWAYS columns such as
     * `search_index.tsv`, which PostgreSQL refuses on INSERT) — so export and restore
     * stay symmetric and the reinsert never hits a generated column.
     *
     * @return list<string>
     */
    private function insertableColumns(string $schema, string $table): array
    {
        $rows = $this->conn()->execute(
            'SELECT column_name FROM information_schema.columns '
            . "WHERE table_schema = :s AND table_name = :t AND is_generated = 'NEVER' "
            . 'ORDER BY ordinal_position',
            ['s' => $schema, 't' => $table],
        )->fetchAll('assoc');

        return array_values(array_map(static fn(array $r): string => (string)$r['column_name'], $rows));
    }

    /**
     * All tenant-scoped tables in the backup set as specs, in forward FK order
     * (parents first): the fixed core list first, then the MODULES' own tenant-scoped
     * tables discovered by introspection (Increment 6d). `sql` is the ready-to-use
     * identifier (module tables are schema-qualified + quoted), `key` the archive
     * NDJSON name, `restorable` whether the scoped restore writes it back.
     *
     * @return list<array{key:string,schema:string,table:string,sql:string,restorable:bool}>
     */
    private function backupTables(): array
    {
        $specs = [];
        foreach (self::TABLES as [$table, $restorable]) {
            $specs[] = ['key' => $table, 'schema' => 'core', 'table' => $table, 'sql' => $table, 'restorable' => $restorable];
        }
        foreach ($this->moduleTableSpecs() as [$schema, $table]) {
            $specs[] = [
                'key' => $schema . '.' . $table,
                'schema' => $schema,
                'table' => $table,
                'sql' => '"' . $schema . '"."' . $table . '"',
                'restorable' => true,
            ];
        }

        return $specs;
    }

    /**
     * The restorable subset of {@see self::backupTables} (excludes the backup-only
     * `users` / `audit_log`).
     *
     * @return list<array{key:string,schema:string,table:string,sql:string,restorable:bool}>
     */
    private function restorableSpecs(): array
    {
        return array_values(array_filter($this->backupTables(), static fn(array $s): bool => $s['restorable']));
    }

    /**
     * The modules' tenant-scoped tables — RLS-enabled tables that carry a `tenant_id`
     * column in a non-core schema — topologically FK-ordered (parents first).
     * Discovered purely by introspection; no module code is touched.
     *
     * @return list<array{0:string,1:string}> [schema, table]
     */
    private function moduleTableSpecs(): array
    {
        $conn = $this->conn();
        $rows = $conn->execute(
            'SELECT n.nspname AS s, c.relname AS t FROM pg_class c '
            . 'JOIN pg_namespace n ON n.oid = c.relnamespace '
            . "WHERE c.relkind = 'r' AND c.relrowsecurity "
            . "AND n.nspname NOT IN ('core', 'public', 'pg_catalog', 'information_schema') "
            . 'AND EXISTS (SELECT 1 FROM information_schema.columns col '
            . "WHERE col.table_schema = n.nspname AND col.table_name = c.relname AND col.column_name = 'tenant_id')",
        )->fetchAll('assoc');
        $nodes = [];
        foreach ($rows as $r) {
            $s = (string)$r['s'];
            $t = (string)$r['t'];
            // Defence: only plain identifiers reach the quoted "schema"."table" SQL
            // (a module schema is the validated module_key; this rejects anything exotic).
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $s) !== 1 || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $t) !== 1) {
                continue;
            }
            $nodes[$s . '.' . $t] = [$s, $t];
        }
        if ($nodes === []) {
            return [];
        }
        $edges = [];
        $fks = $conn->execute(
            "SELECT (ns.nspname || '.' || cl.relname) AS child, (nps.nspname || '.' || cls.relname) AS parent "
            . 'FROM pg_constraint con '
            . 'JOIN pg_class cl ON cl.oid = con.conrelid JOIN pg_namespace ns ON ns.oid = cl.relnamespace '
            . 'JOIN pg_class cls ON cls.oid = con.confrelid JOIN pg_namespace nps ON nps.oid = cls.relnamespace '
            . "WHERE con.contype = 'f'",
        )->fetchAll('assoc');
        foreach ($fks as $f) {
            $child = (string)$f['child'];
            $parent = (string)$f['parent'];
            if (isset($nodes[$child], $nodes[$parent]) && $child !== $parent) {
                $edges[$child][] = $parent;
            }
        }
        $out = [];
        foreach ($this->topoSort(array_keys($nodes), $edges) as $key) {
            $out[] = $nodes[$key];
        }

        return $out;
    }

    /**
     * Topological sort (parents before children) via DFS post-order; cycles are
     * broken by the visited set (no infinite recursion).
     *
     * @param list<string> $nodes
     * @param array<string,list<string>> $edges child => parents
     * @return list<string>
     */
    private function topoSort(array $nodes, array $edges): array
    {
        $sorted = [];
        $visited = [];
        $visit = function (string $n) use (&$visit, &$sorted, &$visited, $edges): void {
            if (isset($visited[$n])) {
                return;
            }
            $visited[$n] = true;
            foreach ($edges[$n] ?? [] as $parent) {
                $visit($parent);
            }
            $sorted[] = $n;
        };
        foreach ($nodes as $n) {
            $visit($n);
        }

        return $sorted;
    }

    /**
     * Packs the export directory into a ZIP. AES-256 when $pw is set — fail-closed:
     * if a password is required but libzip lacks AES-256, abort rather than store a
     * seemingly-encrypted plaintext archive (it contains the tenant's data).
     */
    private function buildZip(string $zipPath, string $srcDir, string $pw): void
    {
        if ($pw !== '' && !defined('ZipArchive::EM_AES_256')) {
            throw new RuntimeException(
                'Backup-Verschluesselung verlangt (backup.password gesetzt), aber libzip ohne '
                . 'AES-256-Support — Abbruch statt unverschluesselter Ablage.',
            );
        }
        $za = new ZipArchive();
        if ($za->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP nicht erstellbar: ' . $zipPath);
        }
        if ($pw !== '') {
            $za->setPassword($pw);
        }
        $entries = $this->addDir($za, $srcDir, '');
        if ($pw !== '') {
            foreach ($entries as $e) {
                if (!$za->setEncryptionName($e, ZipArchive::EM_AES_256)) {
                    $za->close();
                    @unlink($zipPath);

                    throw new RuntimeException("Backup-Eintrag '$e' nicht verschluesselbar — Abbruch.");
                }
            }
        }
        $za->close();
    }

    /**
     * Recursively adds every file under $dir, the entry name being the path relative
     * to the export root — so the per-tenant `files/<...>` subtree is preserved.
     * Returns the entry names (for per-entry AES).
     *
     * @return list<string>
     */
    private function addDir(ZipArchive $za, string $dir, string $prefix): array
    {
        $entries = [];
        foreach ((array)scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $f;
            $entry = $prefix === '' ? (string)$f : $prefix . '/' . $f;
            if (is_dir($path)) {
                $entries = array_merge($entries, $this->addDir($za, $path, $entry));
            } else {
                $za->addFile($path, $entry);
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Copies the current tenant's files (the `tenant/<id>/…` storage subtree) into
     * $destDir preserving their relative paths, so {@see self::buildZip} packs them
     * as `files/<relative>` entries. Returns the file count (0 when the tenant has none).
     */
    private function stageTenantFiles(string $tenantId, string $destDir): int
    {
        $storage = new StorageManager();
        $prefix = TenantStorage::prefix($tenantId);
        try {
            $files = $storage->list($prefix, true);
        } catch (Throwable) {
            return 0;
        }
        $n = 0;
        foreach ($files as $path) {
            $rel = ltrim(substr((string)$path, strlen($prefix)), '/');
            if (!$this->isSafeRelPath($rel)) {
                continue;
            }
            $target = $destDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            @mkdir(dirname($target), 0700, true);
            // Stream — do not load the whole (possibly large) file into memory.
            $in = $storage->readStream((string)$path);
            file_put_contents($target, $in);
            if (is_resource($in)) {
                fclose($in);
            }
            $n++;
        }

        return $n;
    }

    /**
     * Replaces the current tenant's file subtree with the archive's `files/<...>`
     * entries: writes (overwrites) each archived file, THEN prunes any tenant file
     * not in the archive — write-then-prune, so a write failure never leaves the
     * tenant with FEWER files than the backup (no delete-first gap). Returns the
     * number of files written.
     */
    private function restoreTenantFiles(ZipArchive $za, string $tenantId): int
    {
        $storage = new StorageManager();
        $prefix = TenantStorage::prefix($tenantId);
        $restored = [];
        for ($i = 0; $i < $za->numFiles; $i++) {
            $name = (string)$za->getNameIndex($i);
            if (!str_starts_with($name, 'files/')) {
                continue;
            }
            $rel = ltrim(substr($name, strlen('files/')), '/');
            if (!$this->isSafeRelPath($rel)) {
                continue;
            }
            $target = $prefix . $rel;
            // Stream the entry to storage where possible (unencrypted); fall back to
            // an in-memory read for an encrypted entry (getStream cannot decrypt).
            $stream = @$za->getStream($name);
            if (is_resource($stream)) {
                $storage->writeStream($target, $stream);
                fclose($stream);
            } else {
                $content = $za->getFromIndex($i);
                if ($content === false) {
                    continue;
                }
                $storage->write($target, $content);
            }
            $restored[$target] = true;
        }
        // Prune files no longer in the backup. A missing subtree (nothing to list) is
        // fine; a real delete failure must NOT be swallowed.
        $existing = [];
        try {
            $existing = $storage->list($prefix, true);
        } catch (Throwable) {
            $existing = [];
        }
        foreach ($existing as $path) {
            if (!isset($restored[(string)$path])) {
                $storage->delete((string)$path);
            }
        }

        return count($restored);
    }

    /**
     * A per-tenant file relative path is safe iff it is non-empty and cannot escape
     * the `tenant/<id>/` prefix: no `..`, not absolute, no backslash, no drive
     * letter / scheme. Explicit defence in depth — it does NOT rely on the storage
     * layer's path normalization (guards a crafted/uploaded archive on the write side
     * and an unexpected storage listing on the read side).
     */
    private function isSafeRelPath(string $rel): bool
    {
        return $rel !== ''
            && !str_contains($rel, '..')
            && !str_starts_with($rel, '/')
            && !str_contains($rel, '\\')
            && !str_contains($rel, ':');
    }

    /**
     * The current tenant's backups (newest first), for the GUI list.
     *
     * @return list<array<string,mixed>>
     */
    public function listForTenant(): array
    {
        $rows = $this->conn()->execute(
            'SELECT id, filename, bytes, sha256, encrypted, row_counts, note, status, created_at '
            . 'FROM tenant_backups WHERE tenant_id = core.current_tenant() ORDER BY created_at DESC',
        )->fetchAll('assoc');

        return array_values($rows);
    }

    /**
     * The stored archive path of one of the current tenant's backups, or null if it
     * does not belong to this tenant (RLS-scoped) or is missing.
     */
    public function archivePath(string $id): ?string
    {
        if (!$this->isUuid($id)) {
            return null;
        }
        $row = $this->conn()->execute(
            'SELECT storage_path, filename FROM tenant_backups '
            . 'WHERE id = :id AND tenant_id = core.current_tenant()',
            ['id' => $id],
        )->fetch('assoc');
        if ($row === false || !is_file((string)$row['storage_path'])) {
            return null;
        }

        return (string)$row['storage_path'];
    }

    /**
     * Restores one of the CURRENT tenant's stored backups (Increment 6b),
     * DESTRUCTIVELY replacing this tenant's restorable rows with the archive's.
     * Only the calling tenant is touched; other tenants stay live. Returns the
     * per-table reinserted row counts.
     *
     * @return array<string,int>
     */
    public function restore(string $backupId): array
    {
        $tenantId = $this->currentTenantId();
        if ($tenantId === '') {
            throw new RuntimeException(__('flash.tenant_backup.no_tenant'));
        }
        $path = $this->archivePath($backupId);
        if ($path === null) {
            throw new RuntimeException(__('flash.tenant_backup.not_found'));
        }

        return $this->restoreArchive($path, $tenantId);
    }

    /**
     * Core scoped restore: opens the archive (entries read by name — no extraction,
     * so Zip-Slip-safe), verifies the manifest belongs to $tenantId (defence for an
     * uploaded archive — never import another tenant's data), then in ONE transaction
     * deletes the tenant's restorable rows (reverse FK order) and reinserts the
     * archive's (forward FK order). The whole tenant scope is `WHERE tenant_id = :t`,
     * which RLS additionally enforces for the acting tenant admin (no bypass needed);
     * `audit_log`/`users` are never touched. A `tenant.restore` event is appended
     * after a successful commit.
     *
     * @return array<string,int>
     */
    private function restoreArchive(string $zipPath, string $tenantId): array
    {
        $za = new ZipArchive();
        if ($za->open($zipPath) !== true) {
            throw new RuntimeException(__('flash.tenant_backup.invalid_archive'));
        }
        $pw = $this->password();
        if ($pw !== '') {
            $za->setPassword($pw);
        }
        $manifestRaw = $za->getFromName('manifest.json');
        $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'fertura-tenant-backup/1') {
            $za->close();

            throw new RuntimeException(__('flash.tenant_backup.invalid_archive'));
        }
        if ((string)($manifest['tenant_id'] ?? '') !== $tenantId) {
            $za->close();

            throw new RuntimeException(__('flash.tenant_backup.tenant_mismatch'));
        }

        // A SEPARATE privileged connection (in production an independent superuser
        // connection) gives the restore its OWN top-level transaction, so a failure
        // anywhere — e.g. an FK violation on reinsert — rolls back the DELETEs too
        // (guaranteed atomicity; no dependence on a savepoint inside the request
        // transaction, which a caught failure could otherwise leave half-applied =
        // data deleted but not restored). The explicit `WHERE tenant_id = :t` /
        // forced insert `tenant_id` keep it scoped to the acting tenant even though
        // this connection bypasses RLS.
        $conn = Db::privileged();
        $conn->enableSavePoints(true);
        try {
            /** @var array<string,int> $counts */
            $counts = $conn->transactional(function () use ($conn, $tenantId, $za): array {
                $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $tenantId]);
                foreach (array_reverse($this->restorableSpecs()) as $spec) {
                    $conn->execute("DELETE FROM {$spec['sql']} WHERE tenant_id = :t", ['t' => $tenantId]);
                }
                $out = [];
                foreach ($this->restorableSpecs() as $spec) {
                    $raw = $za->getFromName($spec['key'] . '.ndjson');
                    $out[$spec['key']] = is_string($raw) ? $this->importRows($conn, $spec['sql'], $raw, $tenantId) : 0;
                }

                return $out;
            });
            // The DB restore is atomic + committed; audit it NOW so a later file
            // error cannot lose the record.
            (new AuditLogger())->log('tenant.restore', 'tenant', $tenantId, [
                'component' => 'core',
                'newValue' => ['row_counts' => $counts],
            ]);
            // Files are replaced AFTER the DB transaction commits (the filesystem is
            // not transactional); write-then-prune keeps it loss-safe.
            $counts['_files'] = $this->restoreTenantFiles($za, $tenantId);
        } finally {
            $za->close();
        }

        return $counts;
    }

    /**
     * Inserts the NDJSON rows of one table for $tenantId. `tenant_id` is forced to
     * $tenantId (defence against a tampered archive), and every column name is
     * validated as a plain identifier before it reaches the (non-parameterizable)
     * column list — so a crafted archive cannot inject SQL.
     */
    private function importRows(Connection $conn, string $table, string $ndjson, string $tenantId): int
    {
        $n = 0;
        foreach (explode("\n", $ndjson) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            $row['tenant_id'] = $tenantId;
            $cols = array_map('strval', array_keys($row));
            foreach ($cols as $c) {
                if (preg_match('/^[a-z_][a-z0-9_]*$/', $c) !== 1) {
                    throw new RuntimeException(__('flash.tenant_backup.invalid_archive'));
                }
            }
            $names = array_map(static fn(string $c): string => ':' . $c, $cols);
            // Quote the (regex-validated, lowercase) column names so a column that
            // is a PostgreSQL reserved word (e.g. "order", "group") is still valid.
            $quoted = array_map(static fn(string $c): string => '"' . $c . '"', $cols);
            $conn->execute(
                "INSERT INTO $table (" . implode(', ', $quoted) . ') VALUES (' . implode(', ', $names) . ')',
                $row,
            );
            $n++;
        }

        return $n;
    }

    private function isUuid(string $v): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array)scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $f;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
