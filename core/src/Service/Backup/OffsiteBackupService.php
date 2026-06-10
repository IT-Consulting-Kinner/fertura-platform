<?php
declare(strict_types=1);

namespace App\Service\Backup;

use App\Audit\AuditLogger;
use App\Service\Storage\StorageManager;
use RuntimeException;

/**
 * Off-Site-Ablage der Backups (Programm Tier-2, P14): lädt die lokal erzeugten
 * Backup-Archive über den Objekt-Storage (P03) an ein **externes Ziel** (S3-
 * kompatibel) und holt sie für ein Disaster-Recovery zurück.
 *
 * Ergänzt das Core-Backup (Kap. 20.1.2) um Geo-Redundanz, ohne dessen
 * Konsistenz-/Verschlüsselungs-Garantien zu berühren (die Archive sind bereits
 * AES-verschlüsselt). Point-in-Time-Recovery (WAL-Archivierung) ist als
 * Betreiber-Runbook beschrieben (RUNBOOK).
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

    /** Lädt eine lokale Backup-Datei ins Off-Site-Ziel. Gibt den Zielpfad zurück. */
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
     * @return list<string> Off-Site abgelegte Backup-Pfade
     */
    public function list(): array
    {
        return $this->storage->list(rtrim(self::PREFIX, '/'));
    }

    /** Holt ein Off-Site-Backup in eine lokale Datei (Disaster-Recovery). */
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
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($localPath); // keine halb-geschriebene Restore-Datei zurücklassen
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
