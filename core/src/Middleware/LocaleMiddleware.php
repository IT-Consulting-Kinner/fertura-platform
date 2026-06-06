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
 * Setzt die Anzeigesprache pro Request (i18n, E37).
 *
 * Präzedenz (jeweils nur, wenn die Locale aktiviert ist, `locale.enabled`):
 *   1. Expliziter Wechsel `?lang=…` → in der Session gemerkt (Session-Override)
 *   2. Session-Override
 *   3. Benutzer-Präferenz `user.locale`
 *   4. System-Default `locale.default`
 *
 * `I18n::setLocale()` setzt die Übersetzungs-Locale **und** die ICU-Default-Locale
 * (Datums-/Zahlenformat). Die Default-/Fallback-Locale bleibt `App.defaultLocale`
 * (Englisch) → fehlende Schlüssel fallen auf Englisch zurück. Läuft NACH der
 * AuthenticationMiddleware (Identität verfügbar).
 */
class LocaleMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $settings = new SettingsManager();
        $default = (string)$settings->get('core', 'locale.default', 'en_US');
        $enabled = (array)$settings->get('core', 'locale.enabled', ['en_US', 'de_DE']);

        $session = $request->getAttribute('session');

        // 1. ?lang -> Session-Override (nur aktivierte Locales).
        $query = $request->getQueryParams();
        $qlang = isset($query['lang']) ? (string)$query['lang'] : '';
        if ($qlang !== '' && in_array($qlang, $enabled, true) && $session !== null) {
            $session->write('locale', $qlang);
        }

        $locale = null;

        // 2. Session-Override.
        $sessLocale = $session?->read('locale');
        if (is_string($sessLocale) && in_array($sessLocale, $enabled, true)) {
            $locale = $sessLocale;
        }

        // 3. Benutzer-Präferenz.
        if ($locale === null) {
            $identity = $request->getAttribute('identity');
            if ($identity !== null && method_exists($identity, 'getOriginalData')) {
                $userLocale = $identity->getOriginalData()?->get('locale');
                if (is_string($userLocale) && in_array($userLocale, $enabled, true)) {
                    $locale = $userLocale;
                }
            }
        }

        // 4. System-Default (geht nicht auf eine nicht-aktivierte Locale).
        if ($locale === null) {
            $locale = in_array($default, $enabled, true) ? $default : (string)($enabled[0] ?? 'en_US');
        }

        I18n::setLocale($locale);

        return $handler->handle($request);
    }
}
