<?php
declare(strict_types=1);

namespace App\Service\I18n;

use App\Service\Security\CookieSecurity;
use Cake\Http\Cookie\Cookie;
use DateTimeImmutable;

/**
 * Builds the "remember my language" cookie read by {@see \App\Middleware\LocaleMiddleware}.
 *
 * Written on an explicit language switch (`?lang=…` / `/locale/change`) and on a
 * successful login (capturing the language the login mask was shown in), so the
 * choice survives the session and a logout. Hardening mirrors the session cookie
 * (config/app.php): HttpOnly + SameSite=Lax always, Secure fail-safe ON outside
 * local debug/dev (see CookieSecurity).
 */
final class LocaleCookie
{
    /** Cookie name (also the request-cookie key the middleware reads). */
    public const NAME = 'locale';

    public static function make(string $locale): Cookie
    {
        return Cookie::create(self::NAME, $locale, [
            'expires' => new DateTimeImmutable('+1 year'),
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => CookieSecurity::enabled(),
        ]);
    }
}
