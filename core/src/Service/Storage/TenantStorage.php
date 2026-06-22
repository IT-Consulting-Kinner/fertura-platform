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
