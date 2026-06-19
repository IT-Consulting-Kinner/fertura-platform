<?php
declare(strict_types=1);

namespace App\View\Cell;

use App\Application;
use App\Service\I18n\LocaleResolver;
use App\Service\Settings\SettingsManager;
use Cake\I18n\I18n;
use Cake\View\Cell;
use Throwable;

/**
 * Language switcher (i18n-7, E37/E44).
 *
 * Shows the selectable languages (enabled ∩ usable by the core). Switching via
 * `?lang=…` (session override, takes effect everywhere, including public/login).
 * For logged-in users the choice is additionally persisted as `user.locale`
 * (POST to `/locale/change`) when `persist=true`.
 */
class LocaleSwitcherCell extends Cell
{
    public function display(bool $persist = false, string $style = 'buttons'): void
    {
        try {
            $settings = new SettingsManager();
            $enabled = (array)$settings->get('core', 'locale.enabled', ['en_US', 'de_DE']);
            $locales = (new LocaleResolver())->selectableLocales(
                array_map('strval', $enabled),
                Application::CORE_VERSION,
            );
        } catch (Throwable) {
            $locales = ['en_US'];
        }

        $this->set('locales', $locales);
        $this->set('current', I18n::getLocale());
        $this->set('persist', $persist && $this->request->getAttribute('identity') !== null);
        $this->set('style', $style);
    }
}
