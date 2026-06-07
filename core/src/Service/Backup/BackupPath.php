<?php
declare(strict_types=1);

namespace App\Service\Backup;

use RuntimeException;

/**
 * Pfad-Normalisierung/-Validierung für Backup-Ablage und -Restore (Kap. 20.1.2).
 *
 * Akzeptiert **Linux-** (`/mnt/backups`) und **Windows-Pfade** (`C:\Backups`,
 * `\\server\share`) syntaktisch und normalisiert auf Forward-Slashes (die PHP auf
 * beiden Plattformen versteht). Operativ nutzbar ist ein Pfad nur, wenn er zum
 * Laufzeit-OS passt — läuft Fertura im (Linux-)Container, muss ein **gemounteter
 * Linux-Pfad** angegeben werden (ein Windows-Ordner wird per Volume gemountet).
 */
class BackupPath
{
    /** Normalisiert Separatoren auf `/`, entfernt Mehrfach-Slashes + Trailing-Slash. */
    public static function normalize(string $path): string
    {
        $p = str_replace('\\', '/', trim($path));
        $unc = str_starts_with($p, '//');
        $p = (string)preg_replace('#/{2,}#', '/', $p);
        if ($unc) {
            $p = '/' . $p; // führendes // für UNC erhalten
        }
        $p = rtrim($p, '/');

        return $p === '' ? '/' : $p;
    }

    /** Windows-Stil: Laufwerk `C:/…` oder UNC `//server/share` (nach normalize). */
    public static function isWindows(string $normalized): bool
    {
        return (bool)preg_match('#^[A-Za-z]:/#', $normalized) || str_starts_with($normalized, '//');
    }

    /** Linux-Stil: absoluter Pfad `/…` (kein UNC). */
    public static function isLinux(string $normalized): bool
    {
        return str_starts_with($normalized, '/') && !str_starts_with($normalized, '//');
    }

    public static function isAbsolute(string $normalized): bool
    {
        return self::isWindows($normalized) || self::isLinux($normalized);
    }

    /**
     * Validiert einen Pfad für das aktuelle Laufzeit-OS. Wirft mit klarer
     * Meldung, wenn der Stil nicht passt (z. B. Windows-Pfad im Linux-Container).
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
