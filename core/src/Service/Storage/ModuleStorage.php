<?php
declare(strict_types=1);

namespace App\Service\Storage;

/**
 * A per-tenant, per-MODULE storage handle (Inc 8a): every path it builds is forced
 * under `tenant/<current-tenant>/<module-key>/…`, so a module physically cannot place
 * its per-tenant files outside the Core convention. This closes the class of bug where
 * a module wrote attachments to a storage-root path (e.g. `ticketing/<id>/…`) that the
 * per-tenant backup never captured and the consumption meter never counted.
 *
 * The module-facing entry point is {@see self::for()} (or
 * {@see TenantStorage::forModule()}); the {@see \App\Service\Storage\StorageManager}
 * runtime guard refuses a raw write that bypasses this handle during a module dispatch,
 * and a lint rule keeps `new StorageManager()` out of module code — so the convention
 * is enforced, not merely documented.
 *
 * Fail-closed: with no tenant context every operation throws (see
 * {@see TenantStorage::requireTenantId()}), never writing to a tenant-less `tenant//…`.
 * The tenant is resolved LIVE per operation from the RLS context, so the handle stays
 * correct across a worker that iterates tenants within one dispatch.
 */
class ModuleStorage
{
    private string $moduleKey;
    private StorageManager $storage;
    private TenantStorage $tenant;

    /**
     * @param string $moduleKey the owning module's key (a lowercase identifier; it
     *   becomes a path segment, so it is validated to keep the path well-formed)
     */
    public function __construct(string $moduleKey, StorageManager $storage, TenantStorage $tenant)
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $moduleKey) !== 1) {
            throw new StorageException("Ungueltiger Modul-Key fuer Storage: '$moduleKey'.");
        }
        $this->moduleKey = $moduleKey;
        $this->storage = $storage;
        $this->tenant = $tenant;
    }

    /** Convenience factory with the default StorageManager — the module-facing entry point. */
    public static function for(string $moduleKey): self
    {
        $storage = new StorageManager();

        return new self($moduleKey, $storage, new TenantStorage($storage));
    }

    /**
     * The full storage path for $relative under this module's per-tenant subtree
     * (`tenant/<id>/<key>/<relative>`). Modules persist THIS returned value (e.g. in a
     * `storage_path` column) so a later read/delete resolves the same object. Throws
     * when there is no tenant context (fail-closed).
     */
    public function path(string $relative = ''): string
    {
        $prefix = TenantStorage::prefixForModule($this->tenant->requireTenantId(), $this->moduleKey);

        return $prefix . ltrim($relative, '/');
    }

    /** Writes $contents under this module's subtree; returns the full stored path. */
    public function write(string $relative, string $contents): string
    {
        $path = $this->path($relative);
        $this->storage->write($path, $contents);

        return $path;
    }

    /**
     * Streams $resource under this module's subtree; returns the full stored path.
     *
     * @param resource $resource
     */
    public function writeStream(string $relative, $resource): string
    {
        $path = $this->path($relative);
        $this->storage->writeStream($path, $resource);

        return $path;
    }

    public function read(string $relative): string
    {
        return $this->storage->read($this->path($relative));
    }

    /**
     * @return resource
     */
    public function readStream(string $relative)
    {
        return $this->storage->readStream($this->path($relative));
    }

    public function exists(string $relative): bool
    {
        return $this->storage->exists($this->path($relative));
    }

    public function delete(string $relative): void
    {
        $this->storage->delete($this->path($relative));
    }

    public function deleteDirectory(string $relative = ''): void
    {
        $this->storage->deleteDirectory($this->path($relative));
    }

    public function fileSize(string $relative): int
    {
        return $this->storage->fileSize($this->path($relative));
    }

    public function mimeType(string $relative): string
    {
        return $this->storage->mimeType($this->path($relative));
    }

    /**
     * Lists this module's files under $relative.
     *
     * @return list<string> full paths (including the tenant/<id>/<key>/ prefix)
     */
    public function list(string $relative = '', bool $deep = true): array
    {
        return $this->storage->list($this->path($relative), $deep);
    }
}
