<?php
declare(strict_types=1);

namespace App\Service\System;

use Cake\Http\Cookie\Cookie;
use DateTimeImmutable;

/**
 * The maintenance allow-token cookie — Phase 3 (docs/maintenance-mode-design.md §4.3).
 *
 * When an operator engages maintenance, the server issues a 256-bit random token:
 * only its SHA-256 hash is persisted (in core.maintenance_session.allow_token_hash),
 * the plaintext goes here into the operator's browser. The
 * {@see \App\Middleware\SelectiveMaintenanceMiddleware} re-hashes the cookie and
 * compares it with {@see hash_equals()} against the stored hash, so only the operator
 * stays in while everyone else is rejected.
 *
 * Path `/` so the cookie is also presented on `/login` and `/sso/*` (which the gate
 * blocks for everyone else — fail-closed login). Hardening mirrors the session/locale
 * cookies: HttpOnly (not script-readable) + SameSite=Lax always, Secure once TLS is
 * terminated (SESSION_COOKIE_SECURE=1). The token is meaningless once the session is
 * released (the gate no longer matches), but a bounded TTL keeps a stray token from
 * lingering indefinitely.
 */
final class AllowTokenCookie
{
    /** Cookie name (also the request-cookie key the gate reads). */
    public const NAME = 'maint_allow';

    /** Number of random bytes (256-bit token). */
    public const TOKEN_BYTES = 32;

    public static function make(string $plainToken): Cookie
    {
        return Cookie::create(self::NAME, $plainToken, [
            'expires' => new DateTimeImmutable('+6 hours'),
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => filter_var(env('SESSION_COOKIE_SECURE', false), FILTER_VALIDATE_BOOL),
        ]);
    }

    /** A same-named, already-expired cookie — clears the token on release. */
    public static function expire(): Cookie
    {
        return self::make('')->withExpired();
    }
}
