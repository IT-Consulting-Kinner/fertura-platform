<?php
declare(strict_types=1);

namespace App\Service\I18n;

use Cake\Datasource\ConnectionManager;
use RuntimeException;
use Throwable;

/**
 * Managed Locale Store (i18n-3, E38/E40).
 *
 * Verwaltet die Sprachdateien (PO) im persistenten Store und die zugehörigen
 * Metadaten (core.language_packs). Sicheres Schreiben: `.tmp` + fsync →
 * **atomarer Rename** (kein Lösch-Fenster). Recovery unterscheidet laufende
 * Speichervorgänge (PostgreSQL-Advisory-Lock gehalten) von verwaisten `.tmp`
 * (Lock frei) und heilt bzw. bereinigt sie.
 *
 * Layout: <store>/<component_key>/<version>/<locale>/<domain>.po
 * CakePHP liest PO zur Laufzeit direkt → kein separates MO nötig.
 */
class LanguagePackStore
{
    private string $base;

    public function __construct(?string $base = null)
    {
        $this->base = rtrim($base ?? (ROOT . DIRECTORY_SEPARATOR . 'language-store'), '/');
    }

    public function base(): string
    {
        return $this->base;
    }

    public function dir(string $componentKey, string $version, string $locale): string
    {
        return $this->base . '/' . $componentKey . '/' . $version . '/' . $locale;
    }

    public function filePath(string $componentKey, string $version, string $locale, string $domain): string
    {
        return $this->dir($componentKey, $version, $locale) . '/' . $domain . '.po';
    }

    private function conn()
    {
        return ConnectionManager::get('default');
    }

    /**
     * Speichert eine Sprachdatei atomar und aktualisiert die Metadaten.
     * Serialisiert pro Datei über einen Advisory-Lock (paralleler Save →
     * RuntimeException „wird gerade gespeichert").
     *
     * @param array{type:string,domain:string,signed?:bool,reviewed?:bool,edited?:bool,source?:string,uploadedBy?:?string} $meta
     */
    public function save(string $componentKey, string $version, string $locale, string $content, array $meta): void
    {
        $domain = $meta['domain'];
        $file = $this->filePath($componentKey, $version, $locale, $domain);

        $this->withFileLock($file, function () use ($file, $content, $componentKey, $version, $locale, $meta): void {
            $this->upsertMeta($componentKey, $version, $locale, $file, $meta, 'writing');
            try {
                $this->atomicWrite($file, $content);
            } catch (Throwable $e) {
                $this->conn()->execute(
                    'UPDATE language_packs SET write_state = :s, last_write_error = :e '
                    . 'WHERE component_key = :k AND version = :v AND locale = :l',
                    ['s' => 'idle', 'e' => $e->getMessage(), 'k' => $componentKey, 'v' => $version, 'l' => $locale],
                );
                throw $e;
            }
            $this->conn()->execute(
                'UPDATE language_packs SET write_state = :s, last_write_error = NULL, checksum = :c '
                . 'WHERE component_key = :k AND version = :v AND locale = :l',
                ['s' => 'idle', 'c' => hash('sha256', $content), 'k' => $componentKey, 'v' => $version, 'l' => $locale],
            );
        });
    }

    public function read(string $componentKey, string $version, string $locale, string $domain): ?string
    {
        $file = $this->filePath($componentKey, $version, $locale, $domain);

        return is_file($file) ? (string)file_get_contents($file) : null;
    }

    public function delete(string $componentKey, string $version, string $locale, string $domain): void
    {
        $file = $this->filePath($componentKey, $version, $locale, $domain);
        $this->withFileLock($file, function () use ($file, $componentKey, $version, $locale): void {
            @unlink($file);
            @unlink($file . '.tmp');
            $this->conn()->execute(
                'DELETE FROM language_packs WHERE component_key = :k AND version = :v AND locale = :l',
                ['k' => $componentKey, 'v' => $version, 'l' => $locale],
            );
        });
    }

    /**
     * Recovery (Start/periodisch/lazy): geht alle verwaisten `.tmp` durch.
     * In-flight (Lock gehalten) wird übersprungen. Original fehlt + vollständige
     * `.tmp` → promoten (Selbstheilung); sonst verwaiste `.tmp` löschen.
     *
     * @return array{promoted: list<string>, cleaned: list<string>, skipped: list<string>}
     */
    public function recover(): array
    {
        $promoted = [];
        $cleaned = [];
        $skipped = [];
        if (!is_dir($this->base)) {
            return compact('promoted', 'cleaned', 'skipped');
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $f) {
            $tmp = (string)$f;
            if (substr($tmp, -7) !== '.po.tmp') {
                continue;
            }
            $original = substr($tmp, 0, -4); // strip ".tmp"

            $got = $this->tryLockPath($original);
            if (!$got) {
                $skipped[] = $tmp; // laufender Speichervorgang
                continue;
            }
            try {
                $origOk = is_file($original) && filesize($original) > 0;
                $tmpComplete = is_file($tmp) && filesize($tmp) > 0 && str_contains((string)file_get_contents($tmp), 'msgid');
                if (!$origOk && $tmpComplete) {
                    rename($tmp, $original); // Selbstheilung
                    $promoted[] = $original;
                } else {
                    @unlink($tmp); // verwaist / abgebrochen
                    $cleaned[] = $tmp;
                }
            } finally {
                $this->unlockPath($original);
            }
        }

        return compact('promoted', 'cleaned', 'skipped');
    }

    // ---- intern --------------------------------------------------------------

    private function atomicWrite(string $file, string $content): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Store-Verzeichnis nicht anlegbar: $dir");
        }
        $tmp = $file . '.tmp';
        $h = fopen($tmp, 'wb');
        if ($h === false) {
            throw new RuntimeException("Temp-Datei nicht schreibbar: $tmp");
        }
        try {
            if (fwrite($h, $content) === false) {
                throw new RuntimeException("Schreiben fehlgeschlagen: $tmp");
            }
            fflush($h);
            // Vollständig auf Platte zwingen, bevor der Rename committet (PHP 8.1+).
            if (function_exists('fsync')) {
                @fsync($h);
            }
        } finally {
            fclose($h);
        }
        if (!rename($tmp, $file)) {  // atomar auf POSIX: ersetzt das Original
            @unlink($tmp);
            throw new RuntimeException("Atomarer Rename fehlgeschlagen: $file");
        }
    }

    /** @param array<string,mixed> $meta */
    private function upsertMeta(string $componentKey, string $version, string $locale, string $file, array $meta, string $writeState): void
    {
        $this->conn()->execute(
            'INSERT INTO language_packs '
            . '(component_type, component_key, locale, version, domain, signed, reviewed, edited, source, '
            . 'file_path, write_state, write_started_at, uploaded_by) '
            . 'VALUES (:ct, :ck, :l, :v, :d, :sig, :rev, :ed, :src, :fp, :ws, now(), :ub) '
            . 'ON CONFLICT (component_type, component_key, locale, version) DO UPDATE SET '
            . 'domain = EXCLUDED.domain, signed = EXCLUDED.signed, reviewed = EXCLUDED.reviewed, '
            . 'edited = EXCLUDED.edited, source = EXCLUDED.source, file_path = EXCLUDED.file_path, '
            . 'write_state = EXCLUDED.write_state, write_started_at = now(), uploaded_by = EXCLUDED.uploaded_by',
            [
                'ct' => $meta['type'], 'ck' => $componentKey, 'l' => $locale, 'v' => $version, 'd' => $meta['domain'],
                'sig' => !empty($meta['signed']) ? 'true' : 'false',
                'rev' => !empty($meta['reviewed']) ? 'true' : 'false',
                'ed' => !empty($meta['edited']) ? 'true' : 'false',
                'src' => $meta['source'] ?? 'upload',
                'fp' => $file, 'ws' => $writeState, 'ub' => $meta['uploadedBy'] ?? null,
            ],
        );
    }

    private function lockKey(string $path): int
    {
        // hashtext liefert int4; als bigint an pg_advisory_lock.
        $row = $this->conn()->execute('SELECT hashtext(:p)::bigint AS k', ['p' => $path])->fetch('assoc');

        return (int)$row['k'];
    }

    private function tryLockPath(string $path): bool
    {
        $row = $this->conn()->execute('SELECT pg_try_advisory_lock(:k) AS ok', ['k' => $this->lockKey($path)])->fetch('assoc');

        return $row['ok'] === true || $row['ok'] === 't';
    }

    private function unlockPath(string $path): void
    {
        $this->conn()->execute('SELECT pg_advisory_unlock(:k)', ['k' => $this->lockKey($path)]);
    }

    private function withFileLock(string $file, callable $fn): mixed
    {
        if (!$this->tryLockPath($file)) {
            throw new RuntimeException('Sprachdatei wird gerade gespeichert (parallel) — bitte erneut versuchen.');
        }
        try {
            return $fn();
        } finally {
            $this->unlockPath($file);
        }
    }
}
