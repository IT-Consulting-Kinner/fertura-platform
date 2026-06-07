<?php
declare(strict_types=1);

namespace App\View\Cell;

use App\Application;
use App\Service\I18n\LocaleResolver;
use App\Service\Settings\SettingsManager;
use Cake\I18n\I18n;
use Cake\View\Cell;

/**
 * Sprachumschalter (i18n-7, E37/E44).
 *
 * Zeigt die wählbaren Sprachen (aktiviert ∩ vom Core nutzbar). Wechsel via
 * `?lang=…` (Session-Override, greift überall, auch öffentlich/Login). Für
 * angemeldete Nutzer wird die Wahl zusätzlich persistent als `user.locale`
 * gespeichert (POST auf `/locale/set`), wenn `persist=true`.
 */
class LocaleSwitcherCell extends Cell
{
    public function display(bool $persist = false): void
    {
        try {
            $settings = new SettingsManager();
            $enabled = (array)$settings->get('core', 'locale.enabled', ['en_US', 'de_DE']);
            $locales = (new LocaleResolver())->selectableLocales(
                array_map('strval', $enabled),
                Application::CORE_VERSION,
            );
        } catch (\Throwable) {
            $locales = ['en_US'];
        }

        $this->set('locales', $locales);
        $this->set('current', I18n::getLocale());
        $this->set('persist', $persist && $this->request->getAttribute('identity') !== null);
    }
}
