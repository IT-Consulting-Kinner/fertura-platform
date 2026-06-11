<?php
declare(strict_types=1);

namespace App\Service\System;

/**
 * File-based maintenance-mode switch (ch. 28.11).
 *
 * Complements the DB setting `core.maintenance_mode`: for the **restore cutover**
 * (ch. 20.1.2) the 503 switch must live **outside the database**, because the
 * destructive restore replaces the DB (including settings) — a DB flag would be
 * overwritten mid-operation. The flag file lives under `tmp/` and is read by the
 * PHP process (core) that runs the {@see \App\Middleware\MaintenanceMiddleware}.
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

    /** Engages maintenance mode (idempotent). Returns whether it was newly set. */
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
