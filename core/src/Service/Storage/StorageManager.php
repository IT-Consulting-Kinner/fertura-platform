<?php
declare(strict_types=1);

namespace App\Service\Storage;

use App\Service\Settings\SettingsManager;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Throwable;

/**
 * Einheitliche Objekt-Storage-Abstraktion des Core (Programm Tier-2, P03).
 *
 * Kapselt Flysystem hinter einer schmalen Core-API, sodass Backups (P14),
 * Exporte (P13), Modul-Anhänge u. a. unabhängig vom konkreten Ablageort sind:
 * **lokal** (Default) oder **S3-kompatibel** (AWS, MinIO, …). Treiber/Wurzel über
 * `core.storage.driver`/`core.storage.path`; S3-Zugangsdaten **out-of-band** über
 * Env (`STORAGE_S3_*`), nie in der DB.
 */
class StorageManager
{
    private FilesystemOperator $fs;

    public function __construct(?FilesystemOperator $fs = null, ?SettingsManager $settings = null)
    {
        $this->fs = $fs ?? $this->build($settings ?? new SettingsManager());
    }

    public function write(string $path, string $contents): void
    {
        $this->guard(fn () => $this->fs->write($path, $contents), $path);
    }

    /**
     * @param resource $resource
     */
    public function writeStream(string $path, $resource): void
    {
        $this->guard(fn () => $this->fs->writeStream($path, $resource), $path);
    }

    public function read(string $path): string
    {
        return $this->guard(fn () => $this->fs->read($path), $path);
    }

    /**
     * @return resource
     */
    public function readStream(string $path)
    {
        return $this->guard(fn () => $this->fs->readStream($path), $path);
    }

    public function delete(string $path): void
    {
        $this->guard(fn () => $this->fs->delete($path), $path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->guard(fn () => $this->fs->deleteDirectory($path), $path);
    }

    public function exists(string $path): bool
    {
        return $this->guard(fn () => $this->fs->fileExists($path), $path);
    }

    public function fileSize(string $path): int
    {
        return $this->guard(fn () => $this->fs->fileSize($path), $path);
    }

    public function lastModified(string $path): int
    {
        return $this->guard(fn () => $this->fs->lastModified($path), $path);
    }

    public function mimeType(string $path): string
    {
        return $this->guard(fn () => $this->fs->mimeType($path), $path);
    }

    /**
     * Listet Pfade (Dateien) unter `$path` auf.
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
     * Übersetzt Flysystem-Fehler in {@see StorageException}.
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
        if (!class_exists(\AsyncAws\S3\S3Client::class)
            || !class_exists(\League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter::class)) {
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
            // Für S3-kompatible Dienste (MinIO etc.) Path-Style erzwingen.
            'pathStyleEndpoint' => (bool)(getenv('STORAGE_S3_PATH_STYLE') ?: false),
        ], static fn ($v) => $v !== null);

        try {
            $client = new \AsyncAws\S3\S3Client($config);
            $prefix = (string)(getenv('STORAGE_S3_PREFIX') ?: '');

            return new Filesystem(new \League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter($client, $bucket, $prefix));
        } catch (Throwable $e) {
            throw new StorageException('S3-Storage-Initialisierung fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }
    }
}
