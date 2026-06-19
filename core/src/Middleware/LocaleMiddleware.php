<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\Settings\SettingsManager;
use ArrayAccess;
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
                } elseif (is_array($data) || $data instanceof ArrayAccess) {
                    $userLocale = $data['locale'] ?? null;
                }
                if (is_string($userLocale) && in_array($userLocale, $enabled, true)) {
                    $locale = $userLocale;
                }
            }
        }

        // 4. System default. Anonymous/login pages default to en_US (the English-
        // default-GUI norm) — deliberately NOT the browser Accept-Language, so the
        // login always opens in English unless the user explicitly switches.
        if ($locale === null) {
            $locale = in_array($default, $enabled, true) ? $default : (string)($enabled[0] ?? 'en_US');
        }

        I18n::setLocale($locale);

        return $handler->handle($request);
    }
}
