<?php
declare(strict_types=1);

namespace App\Service\Backup;

use RuntimeException;

/**
 * Path normalization/validation for backup storage and restore (ch. 20.1.2).
 *
 * Accepts both **Linux** (`/mnt/backups`) and **Windows paths** (`C:\Backups`,
 * `\\server\share`) syntactically and normalizes them to forward slashes (which
 * PHP understands on both platforms). A path is only operationally usable if it
 * matches the runtime OS — when Fertura runs inside the (Linux) container, a
 * **mounted Linux path** must be supplied (a Windows folder is exposed via a
 * volume mount).
 */
class BackupPath
{
    /** Normalizes separators to `/`, collapses repeated slashes and strips a trailing slash. */
    public static function normalize(string $path): string
    {
        $p = str_replace('\\', '/', trim($path));
        $unc = str_starts_with($p, '//');
        $p = (string)preg_replace('#/{2,}#', '/', $p);
        if ($unc) {
            $p = '/' . $p; // preserve the leading // for UNC paths
        }
        $p = rtrim($p, '/');

        return $p === '' ? '/' : $p;
    }

    /** Windows-style: drive letter `C:/…` or UNC `//server/share` (after normalize). */
    public static function isWindows(string $normalized): bool
    {
        return (bool)preg_match('#^[A-Za-z]:/#', $normalized) || str_starts_with($normalized, '//');
    }

    /** Linux-style: absolute path `/…` (not UNC). */
    public static function isLinux(string $normalized): bool
    {
        return str_starts_with($normalized, '/') && !str_starts_with($normalized, '//');
    }

    public static function isAbsolute(string $normalized): bool
    {
        return self::isWindows($normalized) || self::isLinux($normalized);
    }

    /**
     * Validates a path against the current runtime OS. Throws with a clear
     * message when the style does not match (e.g. a Windows path inside the
     * Linux container).
     */
    public static function assertUsable(string $path): string
    {
        $p = self::normalize($path);
        if (!self::isAbsolute($p)) {
            throw new RuntimeException(
                'Pfad muss absolut sein (Linux: /pfad — Windows: C:/pfad oder //server/share).',
            );
        }
        $win = self::isWindows($p);
        if (PHP_OS_FAMILY === 'Windows' && !$win) {
            throw new RuntimeException('Dieses System läuft unter Windows: bitte einen Windows-Pfad angeben (C:/… oder //server/share).');
        }
        if (PHP_OS_FAMILY !== 'Windows' && $win) {
            throw new RuntimeException(
                'Dieses System läuft unter Linux (Container): bitte einen gemounteten Linux-Pfad angeben '
                . '(einen Windows-Ordner per Docker-Volume mounten und dessen Container-Pfad verwenden).',
            );
        }

        return $p;
    }
}
