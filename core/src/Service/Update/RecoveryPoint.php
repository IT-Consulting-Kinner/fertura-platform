<?php
declare(strict_types=1);

namespace App\Service\Update;

use RuntimeException;
use function Cake\Core\env;

/**
 * Wiederherstellungspunkt via pg_dump (Kap. 28.14.2, Entscheidung 155).
 *
 * Vor jedem migrationsbehafteten Update verpflichtend; gelingt der Dump nicht,
 * wird das Update abgebrochen. Einheitlich für Core- und Modul-Updates.
 */
class RecoveryPoint
{
    /** Wie viele Wiederherstellungspunkte aufbewahrt werden (ältere werden gekappt). */
    private const DEFAULT_KEEP = 10;

    public function dir(): string
    {
        // Auf dem **persistenten** Backup-Volume (nicht im flüchtigen tmp/), damit
        // Wiederherstellungspunkte einen Container-Recreate überleben und neben
        // den Daten-Backups liegen (Kap. 28.14.2 / 20.1.2).
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

    /** Behält die jüngsten N `.sql`-Wiederherstellungspunkte, löscht ältere. */
    public function prune(): int
    {
        $files = glob($this->dir() . '/*.sql') ?: [];
        if (count($files) <= $this->keep()) {
            return 0;
        }
        // Nach Änderungszeit absteigend (neueste zuerst).
        usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
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
     * Erstellt einen vollständigen Dump. Wirft bei Fehlschlag (Abbruch-Bedingung).
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
                'Wiederherstellungspunkt fehlgeschlagen (Update abgebrochen): ' . implode(' ', $out)
            );
        }

        // Aufbewahrung: alte Wiederherstellungspunkte kappen (Volume nicht volllaufen lassen).
        $this->prune();

        return $file;
    }

    /**
     * Spielt einen Wiederherstellungspunkt zurück (letzte Zuflucht).
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
