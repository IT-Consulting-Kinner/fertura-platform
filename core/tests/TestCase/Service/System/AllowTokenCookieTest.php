<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\System;

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

    public function testSecureFollowsTlsEnv(): void
    {
        $prev = $_ENV['SESSION_COOKIE_SECURE'] ?? null;
        // Dev default (HTTP) -> not Secure, so local usage keeps working.
        unset($_ENV['SESSION_COOKIE_SECURE']);
        putenv('SESSION_COOKIE_SECURE');
        $this->assertFalse(AllowTokenCookie::make('t')->isSecure());

        // Once TLS is terminated (SESSION_COOKIE_SECURE=1) the cookie is Secure.
        $_ENV['SESSION_COOKIE_SECURE'] = '1';
        putenv('SESSION_COOKIE_SECURE=1');
        try {
            $this->assertTrue(AllowTokenCookie::make('t')->isSecure());
        } finally {
            if ($prev === null) {
                unset($_ENV['SESSION_COOKIE_SECURE']);
                putenv('SESSION_COOKIE_SECURE');
            } else {
                $_ENV['SESSION_COOKIE_SECURE'] = $prev;
                putenv('SESSION_COOKIE_SECURE=' . $prev);
            }
        }
    }

    public function testExpireIsExpired(): void
    {
        $this->assertTrue(AllowTokenCookie::expire()->isExpired());
    }
}
