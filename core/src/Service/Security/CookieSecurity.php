<?php
declare(strict_types=1);

namespace App\Service\Security;

/**
 * Single source of truth for the Secure flag on the application's cookie family
 * (session, CSRF, locale, remember-me, maintenance allow-token).
 *
 * Fail-safe by design: Secure is ON in every non-debug deployment, so a
 * production box that never set {@see self::ENV_OVERRIDE} still ships hardened
 * cookies instead of leaking them over a stray HTTP path. Only local debug/dev
 * (HTTP, no TLS) drops the flag so the login flow stays usable without a
 * certificate.
 *
 * An explicit {@see self::ENV_OVERRIDE} always wins, both ways: pin it to `0`
 * for an intentional HTTP staging box, or to `1` for a TLS-terminated box that
 * happens to run with debug on.
 *
 * Why {@see env()} and not `Configure::read('debug')`: this also runs from
 * config/app.php while that very `debug` key is still being assembled, so
 * Configure is not yet populated there. `DEBUG` is the same env source the
 * `debug` key derives from, which keeps the bootstrap-time (Session.ini) and
 * runtime (CSRF/cookie services) decisions identical.
 */
final class CookieSecurity
{
    /** Env var that pins the Secure flag explicitly (0/1/true/false). */
    public const ENV_OVERRIDE = 'SESSION_COOKIE_SECURE';

    /**
     * Whether "secure" cookies should carry the Secure flag.
     */
    public static function enabled(): bool
    {
        $override = env(self::ENV_OVERRIDE);
        if ($override !== null && $override !== '') {
            return filter_var($override, FILTER_VALIDATE_BOOLEAN);
        }

        // No explicit pin: fail-safe ON everywhere except local debug/dev.
        return !filter_var(env('DEBUG', false), FILTER_VALIDATE_BOOLEAN);
    }
}
