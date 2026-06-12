<?php
declare(strict_types=1);

namespace App\Service\Backup;

use App\Audit\AuditLogger;
use App\Service\Storage\StorageManager;
use RuntimeException;
use Throwable;

/**
 * Off-site storage of backups (program tier-2, P14): uploads the locally created
 * backup archives via object storage (P03) to an **external target** (S3-
 * compatible) and pulls them back for disaster recovery.
 *
 * Adds geo-redundancy to the core backup (ch. 20.1.2) without touching its
 * consistency/encryption guarantees (the archives are already AES-encrypted).
 * Point-in-time recovery (WAL archiving) is documented as an operator runbook
 * (RUNBOOK).
 */
class OffsiteBackupService
{
    private const PREFIX = 'backups/';

    public function __construct(
        private ?StorageManager $storage = null,
        private ?AuditLogger $audit = null,
    ) {
        $this->storage ??= new StorageManager();
    }

    /** Uploads a local backup file to the off-site target. Returns the target path. */
    public function upload(string $localPath): string
    {
        if (!is_file($localPath)) {
            throw new RuntimeException("Backup-Datei nicht gefunden: $localPath");
        }
        $target = self::PREFIX . basename($localPath);
        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Backup-Datei nicht lesbar: $localPath");
        }
        try {
            $this->storage->writeStream($target, $handle);
        } finally {
            fclose($handle);
        }
        ($this->audit ??= new AuditLogger())->log('backup.offsite.upload', 'backup', $target, [
            'bytes' => (int)filesize($localPath),
        ]);

        return $target;
    }

    /**
     * @return list<string> Backup paths stored off-site
     */
    public function list(): array
    {
        return $this->storage->list(rtrim(self::PREFIX, '/'));
    }

    /** Fetches an off-site backup into a local file (disaster recovery). */
    public function download(string $name, string $localPath): void
    {
        $source = str_starts_with($name, self::PREFIX) ? $name : self::PREFIX . $name;
        $in = $this->storage->readStream($source);
        $out = fopen($localPath, 'wb');
        if ($out === false) {
            throw new RuntimeException("Zieldatei nicht schreibbar: $localPath");
        }
        try {
            stream_copy_to_stream($in, $out);
        } catch (Throwable $e) {
            fclose($out);
            @unlink($localPath); // do not leave a half-written restore file behind
            if (is_resource($in)) {
                fclose($in);
            }
            throw $e;
        }
        fclose($out);
        if (is_resource($in)) {
            fclose($in);
        }
    }

    public function delete(string $name): void
    {
        $source = str_starts_with($name, self::PREFIX) ? $name : self::PREFIX . $name;
        $this->storage->delete($source);
        ($this->audit ??= new AuditLogger())->log('backup.offsite.delete', 'backup', $source, []);
    }
}
