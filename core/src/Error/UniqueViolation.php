<?php
declare(strict_types=1);

namespace App\Error;

use PDOException;
use Throwable;

/**
 * Classifies a PostgreSQL unique-constraint violation (SQLSTATE 23505) out of a
 * thrown error and maps it to a user-facing warning. Used by the two global
 * safety nets — {@see \App\Middleware\UniqueViolationMiddleware} (web) and
 * {@see \App\Console\AppCommandRunner} (CLI) — so that trying to create OR rename
 * an element to a name/value that already exists surfaces as a WARNING instead of
 * a 500 / stack trace, everywhere, without every write site having to handle it.
 *
 * CakePHP wraps the driver error in {@see \Cake\Database\Exception\QueryException}
 * (itself a PDOException whose message carries `SQLSTATE[23505]`), with the raw
 * PDOException available via getPrevious(); either carries the constraint name in
 * the PG detail text. We walk the cause chain and read both.
 */
final class UniqueViolation
{
    /**
     * Known constraint -> specific i18n message key. Anything not listed falls back
     * to the generic `flash.unique_violation` (module constraints included: the web
     * net catches a module's 23505 too and a generic warning is correct there). The
     * value MUST NOT leak another tenant's data — these are all own-scope messages.
     */
    private const MESSAGES = [
        'uq_groups_tenant_name_lower' => 'flash.group.name_exists',
        'uq_users_email_lower' => 'validation.users.email_unique',
        'uq_users_username_lower' => 'validation.users.username_unique',
        'uq_tenants_key' => 'flash.tenant.key_exists',
    ];

    /**
     * The violated unique-constraint name if $e (or any wrapped cause) is a PG 23505,
     * an empty string if it is a 23505 whose constraint name could not be parsed, or
     * null if it is not a unique violation at all.
     */
    public static function constraintOf(Throwable $e): ?string
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            $is23505 = ($t instanceof PDOException && (($t->errorInfo[0] ?? null) === '23505'))
                || str_contains($t->getMessage(), 'SQLSTATE[23505]');
            if ($is23505) {
                return preg_match('/unique constraint "([^"]+)"/', $t->getMessage(), $m) === 1 ? $m[1] : '';
            }
        }

        return null;
    }

    /** Whether $e (or a wrapped cause) is a PostgreSQL unique-constraint violation. */
    public static function isUniqueViolation(Throwable $e): bool
    {
        return self::constraintOf($e) !== null;
    }

    /**
     * The localised, user-facing warning for a caught unique violation. Pass the
     * value from {@see self::constraintOf()} (name, '' or null); a specific message
     * is used when the constraint is mapped, otherwise the generic fallback.
     */
    public static function messageFor(?string $constraint): string
    {
        $key = $constraint !== null && $constraint !== '' && isset(self::MESSAGES[$constraint])
            ? self::MESSAGES[$constraint]
            : 'flash.unique_violation';

        return (string)__($key);
    }
}
