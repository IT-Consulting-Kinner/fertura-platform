<?php
declare(strict_types=1);

namespace App\Service\System;

/**
 * Datei-basierter Wartungsmodus-Schalter (Kap. 28.11).
 *
 * Ergänzt das DB-Setting `core.maintenance_mode`: Für den **Restore-Cutover**
 * (Kap. 20.1.2) muss der 503-Schalter **außerhalb der Datenbank** liegen, weil
 * der destruktive Restore die DB (inkl. Settings) ersetzt — ein DB-Flag würde
 * mitten im Vorgang überschrieben. Die Flag-Datei liegt unter `tmp/` und wird
 * vom PHP-Prozess (Core) gelesen, der die {@see \App\Middleware\MaintenanceMiddleware}
 * ausführt.
 */
final class MaintenanceMode
{
    public static function flagPath(): string
    {
        return ROOT . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'maintenance.flag';
    }

    public static function isFileActive(): bool
    {
        return is_file(self::flagPath());
    }

    /** Aktiviert den Wartungsmodus (idempotent). Gibt zurück, ob er neu gesetzt wurde. */
    public static function engage(string $reason = 'maintenance'): bool
    {
        if (self::isFileActive()) {
            return false;
        }
        $dir = dirname(self::flagPath());
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        file_put_contents(self::flagPath(), $reason . "\n");

        return true;
    }

    public static function release(): void
    {
        if (self::isFileActive()) {
            @unlink(self::flagPath());
        }
    }
}
