<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\System;

use App\Service\Security\CookieSecurity;
use App\Service\System\AllowTokenCookie;
use Cake\TestSuite\TestCase;

/**
 * The maintenance allow-token cookie (Phase 3): correct name/scope/hardening and a
 * working expiry for release.
 */
class AllowTokenCookieTest extends TestCase
{
    public function testMakeIsScopedAndHardened(): void
    {
        $cookie = AllowTokenCookie::make('the-token');
        $this->assertSame('maint_allow', $cookie->getName());
        $this->assertSame('the-token', $cookie->getValue());
        $this->assertSame('/', $cookie->getPath()); // also sent on /login + /sso/*
        $this->assertTrue($cookie->isHttpOnly()); // not script-readable
        $this->assertSame('Lax', $cookie->getSameSite()?->value);
    }

    /** An explicit pin wins over the debug fallback — in both directions. */
    public function testSecureFollowsTlsEnv(): void
    {
        $prevSecure = self::readEnv(CookieSecurity::ENV_OVERRIDE);
        $prevDebug = self::readEnv('DEBUG');
        try {
            // TLS-terminated box that happens to run with debug on: pin wins -> Secure.
            self::pinEnv('DEBUG', '1');
            self::pinEnv(CookieSecurity::ENV_OVERRIDE, '1');
            $this->assertTrue(AllowTokenCookie::make('t')->isSecure());

            // Intentional HTTP staging box without debug: pin wins -> not Secure.
            self::pinEnv('DEBUG', '0');
            self::pinEnv(CookieSecurity::ENV_OVERRIDE, '0');
            $this->assertFalse(AllowTokenCookie::make('t')->isSecure());
        } finally {
            self::pinEnv(CookieSecurity::ENV_OVERRIDE, $prevSecure);
            self::pinEnv('DEBUG', $prevDebug);
        }
    }

    /**
     * Without an explicit pin the flag follows DEBUG. DEBUG is pinned here rather
     * than inherited: the ambient value differs per environment (dev container sets
     * DEBUG=1, CI leaves it unset), which would otherwise flip this test's outcome.
     */
    public function testSecureFailsSafeWithoutPin(): void
    {
        $prevSecure = self::readEnv(CookieSecurity::ENV_OVERRIDE);
        $prevDebug = self::readEnv('DEBUG');
        try {
            self::pinEnv(CookieSecurity::ENV_OVERRIDE, null);

            // Local debug/dev (HTTP, no TLS): drop the flag so login stays usable.
            self::pinEnv('DEBUG', '1');
            $this->assertFalse(AllowTokenCookie::make('t')->isSecure());

            // Every other deployment: fail-safe ON even though nobody set the pin.
            self::pinEnv('DEBUG', '0');
            $this->assertTrue(AllowTokenCookie::make('t')->isSecure());
        } finally {
            self::pinEnv(CookieSecurity::ENV_OVERRIDE, $prevSecure);
            self::pinEnv('DEBUG', $prevDebug);
        }
    }

    public function testExpireIsExpired(): void
    {
        $this->assertTrue(AllowTokenCookie::expire()->isExpired());
    }

    /** Read an env var the way env() resolves it: $_SERVER, then $_ENV, then getenv(). */
    private static function readEnv(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;
        if ($value === null) {
            $fromGetenv = getenv($name);
            $value = $fromGetenv === false ? null : $fromGetenv;
        }

        return $value === null ? null : (string)$value;
    }

    /**
     * Pin (or clear with null) an env var in every source env() consults. Touching
     * only one of them is not enough: $_SERVER shadows $_ENV, so a leftover entry
     * there would keep the old value visible.
     */
    private static function pinEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name . '=' . $value);
    }
}
