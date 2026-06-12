<?php
declare(strict_types=1);

namespace App\Log;

/**
 * Process-wide log context (ch. 20.2.3): holds operative fields
 * (`correlation_id`, `request_id`, `component`, `module`) that the
 * `ContextJsonFormatter` automatically merges into **every** log line — without
 * them having to be supplied at the call site.
 */
class LogContext
{
    /**
     * @var array<string, scalar>
     */
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
