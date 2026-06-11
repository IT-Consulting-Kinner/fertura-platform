<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\Settings\SettingsManager;
use Cake\I18n\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sets the display language per request (i18n, E37).
 *
 * Precedence (each only if the locale is enabled, `locale.enabled`):
 *   1. Explicit switch `?lang=…` → stored in the session (session override)
 *   2. Session override
 *   3. User preference `user.locale`
 *   4. System default `locale.default`
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
        $enabled = (array)$settings->get('core', 'locale.enabled', ['en_US', 'de_DE']);

        $session = $request->getAttribute('session');

        // 1. ?lang -> session override (enabled locales only).
        $query = $request->getQueryParams();
        $qlang = isset($query['lang']) ? (string)$query['lang'] : '';
        if ($qlang !== '' && in_array($qlang, $enabled, true) && $session !== null) {
            $session->write('locale', $qlang);
        }

        $locale = null;

        // 2. Session override.
        $sessLocale = $session?->read('locale');
        if (is_string($sessLocale) && in_array($sessLocale, $enabled, true)) {
            $locale = $sessLocale;
        }

        // 3. User preference.
        if ($locale === null) {
            $identity = $request->getAttribute('identity');
            if ($identity !== null && method_exists($identity, 'getOriginalData')) {
                $data = $identity->getOriginalData();
                // Data may be an ORM entity (->get) or an ArrayObject/array
                // (e.g. token identity) — handle both robustly.
                $userLocale = null;
                if (is_object($data) && method_exists($data, 'get')) {
                    $userLocale = $data->get('locale');
                } elseif (is_array($data) || $data instanceof \ArrayAccess) {
                    $userLocale = $data['locale'] ?? null;
                }
                if (is_string($userLocale) && in_array($userLocale, $enabled, true)) {
                    $locale = $userLocale;
                }
            }
        }

        // 3b. Accept-Language (mainly public/login without session/identity).
        if ($locale === null) {
            $locale = $this->matchAcceptLanguage(
                (string)($request->getHeaderLine('Accept-Language')),
                array_map('strval', $enabled),
            );
        }

        // 4. System default (never falls onto a non-enabled locale).
        if ($locale === null) {
            $locale = in_array($default, $enabled, true) ? $default : (string)($enabled[0] ?? 'en_US');
        }

        I18n::setLocale($locale);

        return $handler->handle($request);
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
        usort($candidates, static fn ($a, $b) => $b[1] <=> $a[1]);

        foreach ($candidates as [$tag, $q]) {
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
