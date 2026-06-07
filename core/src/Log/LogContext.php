<?php
declare(strict_types=1);

namespace App\Log;

/**
 * Prozessweiter Log-Kontext (Kap. 20.2.3): hält operative Felder
 * (`correlation_id`, `request_id`, `component`, `module`), die der
 * `ContextJsonFormatter` automatisch in **jede** Logzeile einmischt — ohne dass
 * sie am Aufrufort mitgegeben werden müssen.
 */
class LogContext
{
    /** @var array<string, scalar> */
    private static array $ctx = [];

    /** @param array<string, scalar> $fields */
    public static function set(array $fields): void
    {
        self::$ctx = $fields + self::$ctx;
    }

    public static function put(string $key, string|int|float|bool $value): void
    {
        self::$ctx[$key] = $value;
    }

    /** @return array<string, scalar> */
    public static function all(): array
    {
        return self::$ctx;
    }

    public static function clear(): void
    {
        self::$ctx = [];
    }
}
