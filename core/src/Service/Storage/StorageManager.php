<?php
declare(strict_types=1);

namespace App\Service\Storage;

use App\Service\Settings\SettingsManager;
use AsyncAws\S3\S3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Throwable;

/**
 * The core's uniform object-storage abstraction (program Tier-2, P03).
 *
 * Wraps Flysystem behind a thin core API so that backups (P14), exports (P13),
 * module attachments, etc. are independent of the concrete storage location:
 * **local** (default) or **S3-compatible** (AWS, MinIO, …). Driver/root via
 * `core.storage.driver`/`core.storage.path`; S3 credentials **out-of-band** via
 * env (`STORAGE_S3_*`), never in the DB.
 */
class StorageManager
{
    /**
     * Non-tenant path prefixes a module MAY still write to while it is dispatching
     * (Inc 8b allow-list). Core's export-to-storage lands under `reports/` (large
     * downloads streamed back via the module-download path), which is legitimately
     * not per-tenant; everything else a module writes must live under its
     * `tenant/<id>/<key>/` subtree. Keep this list as small as possible.
     *
     * @var list<string>
     */
    private const MODULE_DISPATCH_ALLOWED_PREFIXES = ['reports/'];

    private FilesystemOperator $fs;

    public function __construct(?FilesystemOperator $fs = null, ?SettingsManager $settings = null)
    {
        $this->fs = $fs ?? $this->build($settings ?? new SettingsManager());
    }

    public function write(string $path, string $contents): void
    {
        $this->guardModuleScope($path);
        $this->guard(fn() => $this->fs->write($path, $contents), $path);
    }

    /**
     * @param resource $resource
     */
    public function writeStream(string $path, $resource): void
    {
        $this->guardModuleScope($path);
        $this->guard(fn() => $this->fs->writeStream($path, $resource), $path);
    }

    public function read(string $path): string
    {
        return $this->guard(fn() => $this->fs->read($path), $path);
    }

    /**
     * @return resource
     */
    public function readStream(string $path)
    {
        return $this->guard(fn() => $this->fs->readStream($path), $path);
    }

    public function delete(string $path): void
    {
        $this->guardModuleScope($path);
        $this->guard(fn() => $this->fs->delete($path), $path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->guardModuleScope($path);
        $this->guard(fn() => $this->fs->deleteDirectory($path), $path);
    }

    public function exists(string $path): bool
    {
        return $this->guard(fn() => $this->fs->fileExists($path), $path);
    }

    public function fileSize(string $path): int
    {
        return $this->guard(fn() => $this->fs->fileSize($path), $path);
    }

    public function lastModified(string $path): int
    {
        return $this->guard(fn() => $this->fs->lastModified($path), $path);
    }

    public function mimeType(string $path): string
    {
        return $this->guard(fn() => $this->fs->mimeType($path), $path);
    }

    /**
     * Lists paths (files) under `$path`.
     *
     * @return list<string>
     */
    public function list(string $path = '', bool $deep = false): array
    {
        return $this->guard(function () use ($path, $deep): array {
            $out = [];
            foreach ($this->fs->listContents($path, $deep) as $item) {
                if ($item->isFile()) {
                    $out[] = $item->path();
                }
            }

            return $out;
        }, $path);
    }

    /**
     * Enforces the per-module file convention (Inc 8b): while a module contribution is
     * dispatching ({@see ModuleStorageScope} is active), a mutating storage op MUST
     * target that module's per-tenant subtree `tenant/<id>/<module-key>/…` — the path
     * {@see ModuleStorage} builds. A raw path (e.g. a module writing `ticketing/…` at
     * the storage root via a bare StorageManager) is REFUSED, so files that the
     * per-tenant backup would miss and the consumption meter would under-count can
     * never be written in the first place. A short allow-list covers Core's own
     * non-tenant writes that may occur while a module runs. Core code (no active scope)
     * is never restricted; reads are never guarded.
     */
    private function guardModuleScope(string $path): void
    {
        $moduleKey = ModuleStorageScope::active();
        if ($moduleKey === null) {
            return;
        }
        // The sanctioned target: tenant/<any-tenant>/<moduleKey>/…. The tenant segment
        // is not pinned here (a worker may iterate tenants within one dispatch);
        // ModuleStorage resolves the correct live tenant, this only enforces structure.
        if (preg_match('#^tenant/[^/]+/' . preg_quote($moduleKey, '#') . '/#', $path) === 1) {
            return;
        }
        foreach (self::MODULE_DISPATCH_ALLOWED_PREFIXES as $allowed) {
            if (str_starts_with($path, $allowed)) {
                return;
            }
        }

        throw new StorageException(
            "Modul '$moduleKey' darf per-Tenant-Dateien nur unter tenant/<id>/$moduleKey/ ablegen "
            . "(verweigerter Pfad: '$path'). Nutze ModuleStorage::for('$moduleKey').",
        );
    }

    /**
     * Translates Flysystem errors into {@see StorageException}.
     *
     * @template T
     * @param callable():T $op
     * @return T
     */
    private function guard(callable $op, string $path): mixed
    {
        try {
            return $op();
        } catch (FilesystemException $e) {
            throw new StorageException("Storage-Operation fehlgeschlagen ($path): " . $e->getMessage(), 0, $e);
        }
    }

    private function build(SettingsManager $settings): FilesystemOperator
    {
        $driver = (string)$settings->get('core', 'storage.driver', 'local');
        if ($driver === 's3') {
            return $this->buildS3();
        }

        $root = (string)($settings->get('core', 'storage.path', null) ?: (ROOT . DIRECTORY_SEPARATOR . 'storage'));

        return new Filesystem(new LocalFilesystemAdapter($root));
    }

    private function buildS3(): FilesystemOperator
    {
        if (
            !class_exists(S3Client::class)
            || !class_exists(AsyncAwsS3Adapter::class)
        ) {
            throw new StorageException('S3-Treiber nicht verfügbar (league/flysystem-async-aws-s3 fehlt).');
        }
        $bucket = (string)getenv('STORAGE_S3_BUCKET');
        if ($bucket === '') {
            throw new StorageException('STORAGE_S3_BUCKET ist nicht gesetzt.');
        }
        $config = array_filter([
            'region' => getenv('STORAGE_S3_REGION') ?: 'us-east-1',
            'endpoint' => getenv('STORAGE_S3_ENDPOINT') ?: null,
            'accessKeyId' => getenv('STORAGE_S3_KEY') ?: null,
            'accessKeySecret' => getenv('STORAGE_S3_SECRET') ?: null,
            // Force path-style for S3-compatible services (MinIO etc.).
            'pathStyleEndpoint' => (bool)(getenv('STORAGE_S3_PATH_STYLE') ?: false),
        ], static fn($v) => $v !== null);

        try {
            $client = new S3Client($config);
            $prefix = (string)(getenv('STORAGE_S3_PREFIX') ?: '');

            return new Filesystem(new AsyncAwsS3Adapter($client, $bucket, $prefix));
        } catch (Throwable $e) {
            throw new StorageException('S3-Storage-Initialisierung fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }
    }
}
