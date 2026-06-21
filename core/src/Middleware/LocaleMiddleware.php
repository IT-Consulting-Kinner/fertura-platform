<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\I18n\LocaleCookie;
use App\Service\Settings\SettingsManager;
use ArrayAccess;
use Cake\Http\Response as CakeResponse;
use Cake\I18n\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sets the display language per request (i18n, E37).
 *
 * Precedence (each only if the locale is enabled, `locale.enabled`):
 *   1. Explicit switch `?lang=…` → session override + a remembering cookie
 *   2. Session override
 *   3. Remembering cookie (an earlier explicit choice / the login-time language)
 *   4. User preference `user.locale` (authenticated, e.g. on a fresh browser)
 *   5. Browser language (`Accept-Language`) — mainly anonymous/login
 *   6. System default `locale.default` (English)
 *
 * So an anonymous visitor without a cookie follows the browser language; if that
 * language has no usable catalog it falls through to English (both via this
 * precedence and via the English-base merge in the i18n loaders, which keeps any
 * untranslated key — core or module — in English). Once the user picks a language
 * (or logs in) the cookie remembers it across sessions and a logout.
 *
 * `I18n::setLocale()` sets the translation locale **and** the ICU default locale
 * (date/number formatting). The default/fallback locale stays `App.defaultLocale`
 * (English) → missing keys fall back to English. Runs AFTER the
 * AuthenticationMiddleware (identity available).
 */
class LocaleMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $settings = new SettingsManager();
        $default = (string)$settings->get('core', 'locale.default', 'en_US');
        // array_values() re-keys to a genuine list<string>: the enabled locales are
        // an ordered list (first-enabled fallback below, preference-ordered matching),
        // and the (array) cast/array_map would otherwise preserve non-sequential keys.
        $enabled = array_values(array_map('strval', (array)$settings->get('core', 'locale.enabled', ['en_US', 'de_DE'])));

        $session = $request->getAttribute('session');

        // 1. ?lang -> explicit switch: session override (enabled locales only).
        //    A remembering cookie is written onto the response further below.
        $query = $request->getQueryParams();
        $qlang = isset($query['lang']) ? (string)$query['lang'] : '';
        $switched = $qlang !== '' && in_array($qlang, $enabled, true);
        if ($switched && $session !== null) {
            $session->write('locale', $qlang);
        }

        $locale = null;

        // 2. Session override.
        $sessLocale = $session?->read('locale');
        if (is_string($sessLocale) && in_array($sessLocale, $enabled, true)) {
            $locale = $sessLocale;
        }

        // 3. Remembering cookie: an earlier explicit choice or the language the
        //    login mask was shown in — kept across sessions and a logout.
        if ($locale === null) {
            $cookieLocale = $request->getCookieParams()[LocaleCookie::NAME] ?? null;
            if (is_string($cookieLocale) && in_array($cookieLocale, $enabled, true)) {
                $locale = $cookieLocale;
            }
        }

        // 4. User preference (authenticated) — e.g. on a browser without the cookie.
        if ($locale === null) {
            $locale = $this->userPreferredLocale($request, $enabled);
        }

        // 5. Browser language (Accept-Language) — mainly anonymous/login without a
        //    cookie yet. A language with no enabled catalog yields null → English.
        if ($locale === null) {
            $locale = $this->matchAcceptLanguage($request->getHeaderLine('Accept-Language'), $enabled);
        }

        // 6. System default (never falls onto a non-enabled locale).
        if ($locale === null) {
            $locale = in_array($default, $enabled, true) ? $default : (string)($enabled[0] ?? 'en_US');
        }

        I18n::setLocale($locale);

        $response = $handler->handle($request);

        // Persist an explicit ?lang switch as a long-lived cookie so the choice is
        // remembered on the next visit (see LocaleCookie for the hardening).
        if ($switched && $response instanceof CakeResponse) {
            $response = $response->withCookie(LocaleCookie::make($qlang));
        }

        return $response;
    }

    /**
     * The authenticated user's `user.locale`, if it is set and enabled.
     *
     * @param list<string> $enabled
     */
    private function userPreferredLocale(ServerRequestInterface $request, array $enabled): ?string
    {
        $identity = $request->getAttribute('identity');
        if ($identity === null || !method_exists($identity, 'getOriginalData')) {
            return null;
        }
        // Data may be an ORM entity (->get) or an ArrayObject/array (e.g. token
        // identity) — handle both robustly.
        $data = $identity->getOriginalData();
        $userLocale = null;
        if (is_object($data) && method_exists($data, 'get')) {
            $userLocale = $data->get('locale');
        } elseif (is_array($data) || $data instanceof ArrayAccess) {
            $userLocale = $data['locale'] ?? null;
        }

        return is_string($userLocale) && in_array($userLocale, $enabled, true) ? $userLocale : null;
    }

    /**
     * Best enabled locale from the Accept-Language header (by q weight).
     * Direct `ll_CC` or language prefix `ll` → first enabled `ll_*`.
     *
     * @param list<string> $enabled
     */
    private function matchAcceptLanguage(string $header, array $enabled): ?string
    {
        if (trim($header) === '') {
            return null;
        }
        $candidates = [];
        foreach (explode(',', $header) as $part) {
            $bits = explode(';', trim($part));
            $tag = trim($bits[0]);
            if ($tag === '' || $tag === '*') {
                continue;
            }
            $q = 1.0;
            if (isset($bits[1]) && str_starts_with(trim($bits[1]), 'q=')) {
                $q = (float)substr(trim($bits[1]), 2);
            }
            $candidates[] = [str_replace('-', '_', $tag), $q];
        }
        usort($candidates, static fn($a, $b) => $b[1] <=> $a[1]);

        foreach ($candidates as [$tag]) {
            if (in_array($tag, $enabled, true)) {
                return $tag;
            }
            // Language prefix: 'de' → first enabled 'de_*'.
            $lang = strtolower(explode('_', $tag)[0]);
            foreach ($enabled as $e) {
                if (strtolower(explode('_', $e)[0]) === $lang) {
                    return $e;
                }
            }
        }

        return null;
    }
}
