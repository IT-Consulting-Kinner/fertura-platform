<?php
declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Well-formedness check for UUID values from URL/query/form parameters.
 *
 * Raw SQL (`execute()`) binds parameters untyped; a malformed value against a
 * PG uuid column throws SQLSTATE 22P02 ("invalid input syntax for type uuid")
 * and thus HTTP 500 instead of a clean "not found". Callers therefore validate
 * BEFORE binding and treat malformed IDs like unknown ones. ORM paths
 * (`find()->where()`) are not affected — there the ORM types correctly.
 */
final class Uuid
{
    /** Well-formed UUID (canonical 8-4-4-4-12 format, case-insensitive)? */
    public static function isValid(string $value): bool
    {
        // \z instead of $: a trailing newline must NOT slip through.
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value) === 1;
    }
}
