<?php
declare(strict_types=1);

namespace App\Service\Update;

use RuntimeException;
use function Cake\Core\env;

/**
 * Recovery point via pg_dump (ch. 28.14.2, Decision 155).
 *
 * Mandatory before every migration-bearing update; if the dump fails, the update
 * is aborted. Uniform for core and module updates.
 */
class RecoveryPoint
{
    /** How many recovery points are retained (older ones are pruned). */
    private const DEFAULT_KEEP = 10;

    public function dir(): string
    {
        // On the **persistent** backup volume (not in the ephemeral tmp/), so that
        // recovery points survive a container recreate and sit alongside the data
        // backups (ch. 28.14.2 / 20.1.2).
        $dir = ROOT . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'recovery';
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        return $dir;
    }

    private function keep(): int
    {
        $k = (int)(env('RECOVERY_KEEP') ?: self::DEFAULT_KEEP);

        return $k > 0 ? $k : self::DEFAULT_KEEP;
    }

    /** Keeps the most recent N `.sql` recovery points, deletes older ones. */
    public function prune(): int
    {
        $files = glob($this->dir() . '/*.sql') ?: [];
        if (count($files) <= $this->keep()) {
            return 0;
        }
        // By modification time, descending (newest first).
        usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        $stale = array_slice($files, $this->keep());
        foreach ($stale as $f) {
            @unlink($f);
        }

        return count($stale);
    }

    /**
     * @return array{host: string, port: int, db: string, user: string, pass: string}
     */
    private function dsn(): array
    {
        $url = (string)env('DATABASE_URL');
        $p = parse_url($url) ?: [];

        return [
            'host' => $p['host'] ?? 'db',
            'port' => (int)($p['port'] ?? 5432),
            'db' => isset($p['path']) ? ltrim($p['path'], '/') : 'fertura',
            'user' => $p['user'] ?? 'fertura',
            'pass' => $p['pass'] ?? '',
        ];
    }

    /**
     * Creates a full dump. Throws on failure (abort condition).
     */
    public function create(string $label): string
    {
        $d = $this->dsn();
        $file = $this->dir() . '/' . preg_replace('/[^a-z0-9_]/i', '_', $label) . '_' . date('Ymd_His') . '.sql';

        $cmd = sprintf(
            'PGPASSWORD=%s pg_dump -h %s -p %d -U %s -d %s --no-owner --no-privileges --clean --if-exists -f %s 2>&1',
            escapeshellarg($d['pass']),
            escapeshellarg($d['host']),
            $d['port'],
            escapeshellarg($d['user']),
            escapeshellarg($d['db']),
            escapeshellarg($file),
        );

        $out = [];
        $code = 0;
        exec($cmd, $out, $code);

        if ($code !== 0 || !is_file($file) || filesize($file) === 0) {
            throw new RuntimeException(
                'Wiederherstellungspunkt fehlgeschlagen (Update abgebrochen): ' . implode(' ', $out),
            );
        }

        // Retention: prune old recovery points (don't let the volume fill up).
        $this->prune();

        return $file;
    }

    /**
     * Restores a recovery point (last resort).
     */
    public function restore(string $file): void
    {
        if (!is_file($file)) {
            throw new RuntimeException("Wiederherstellungspunkt nicht gefunden: $file");
        }
        $d = $this->dsn();
        $cmd = sprintf(
            'PGPASSWORD=%s psql -h %s -p %d -U %s -d %s -v ON_ERROR_STOP=1 -f %s 2>&1',
            escapeshellarg($d['pass']),
            escapeshellarg($d['host']),
            $d['port'],
            escapeshellarg($d['user']),
            escapeshellarg($d['db']),
            escapeshellarg($file),
        );
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        if ($code !== 0) {
            throw new RuntimeException('Restore fehlgeschlagen: ' . implode(' ', $out));
        }
    }
}
