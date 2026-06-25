<?php
declare(strict_types=1);

namespace App\Service\Storage;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * Per-tenant file scoping convention (tenant-backup design §4, Increment 6c).
 *
 * All of a tenant's files live under the `tenant/<tenant_id>/…` prefix in the
 * {@see StorageManager} root, so per-tenant backup/restore can address exactly one
 * tenant's subtree. Modules ADOPT the convention declaratively by writing their
 * per-tenant files through this helper (or under the same prefix) — the Core never
 * edits module code. Files stored outside the prefix are not per-tenant-backupable.
 */
class TenantStorage
{
    private StorageManager $storage;

    public function __construct(?StorageManager $storage = null)
    {
        $this->storage = $storage ?? new StorageManager();
    }

    /** The storage prefix (with trailing slash) for $tenantId's files. */
    public static function prefix(string $tenantId): string
    {
        return 'tenant/' . $tenantId . '/';
    }

    /**
     * The storage prefix (with trailing slash) for ONE module's files of $tenantId:
     * `tenant/<id>/<module-key>/`. This per-module sub-bucket is what lets a
     * scope=<module-key> backup capture exactly that module's files and a per-tenant
     * restore put them back — see {@see ModuleStorage}, the handle modules write
     * through so they cannot accidentally land outside the tenant tree.
     */
    public static function prefixForModule(string $tenantId, string $moduleKey): string
    {
        return self::prefix($tenantId) . $moduleKey . '/';
    }

    /**
     * A storage handle SCOPED to one module's per-tenant subtree
     * (`tenant/<current-tenant>/<module-key>/…`). The blessed way for a module to
     * persist per-tenant files: every path it builds is forced under the convention,
     * so its files are always backup-discoverable and consumption-counted. Fail-closed
     * — writing with no tenant context throws (see {@see ModuleStorage}).
     */
    public function forModule(string $moduleKey): ModuleStorage
    {
        return new ModuleStorage($moduleKey, $this->storage, $this);
    }

    /**
     * The current request tenant from the RLS context, or throw when there is none
     * (fail-closed): a per-tenant file write without a tenant context would otherwise
     * land at `tenant//…` and belong to no tenant. Used by {@see ModuleStorage}.
     */
    public function requireTenantId(): string
    {
        $tenantId = $this->currentTenantId();
        if ($tenantId === '') {
            throw new StorageException('Kein Mandantenkontext — per-Tenant-Datei nicht ablegbar (fail-closed).');
        }

        return $tenantId;
    }

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /** The current request tenant from the RLS context; '' when unset. */
    private function currentTenantId(): string
    {
        $row = $this->conn()->execute('SELECT core.current_tenant() AS t')->fetch('assoc');

        return $row !== false && $row['t'] !== null ? (string)$row['t'] : '';
    }

    /**
     * Resolves $relative under the CURRENT tenant's prefix — the path a module uses
     * to store/read one of its per-tenant files (e.g. tenantPath('uploads/x.pdf')).
     */
    public function tenantPath(string $relative = ''): string
    {
        return self::prefix($this->currentTenantId()) . ltrim($relative, '/');
    }

    public function write(string $relative, string $contents): void
    {
        $this->storage->write($this->tenantPath($relative), $contents);
    }

    public function read(string $relative): string
    {
        return $this->storage->read($this->tenantPath($relative));
    }

    /**
     * Lists the current tenant's files under $relative.
     *
     * @return list<string> paths (relative to the storage root, i.e. including the prefix)
     */
    public function list(string $relative = '', bool $deep = true): array
    {
        return $this->storage->list($this->tenantPath($relative), $deep);
    }
}
