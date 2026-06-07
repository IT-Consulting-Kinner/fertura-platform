<?php
declare(strict_types=1);

namespace App\I18n;

use App\Service\I18n\LanguagePackStore;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\I18n;
use Cake\I18n\Package;
use Cake\I18n\Parser\PoFileParser;
use Throwable;

/**
 * Registriert Übersetzungs-Domains, die ihre Kataloge aus dem **Managed Locale
 * Store** laden (i18n-4) — Englisch als Basis, gewählte Locale darüber (E37/E39).
 *
 * Im Gegensatz zu {@see EnglishFallbackLoader} (Core-Domain `default` aus
 * resources/locales) lesen Modul-/Extension-Domains aus dem Store für die
 * jeweils aktive Komponentenversion.
 */
class StoreLocaleLoader
{
    public const BASE_LOCALE = 'en_US';

    public static function register(string $domain, string $componentKey, string $version): void
    {
        I18n::config($domain, static function (string $name, string $locale) use ($componentKey, $version, $domain): Package {
            $store = new LanguagePackStore();
            $messages = self::load($store, $componentKey, $version, self::BASE_LOCALE, $domain);
            if ($locale !== self::BASE_LOCALE) {
                $messages = array_merge($messages, self::load($store, $componentKey, $version, $locale, $domain));
            }

            return new Package('default', null, $messages);
        });
    }

    /**
     * Registriert die Domains aller aktiven Module/Extensions, deren
     * Sprachdateien in der für die aktive Version passenden Fassung im Store
     * liegen. Fehlertolerant (DB/Store evtl. noch nicht verfügbar).
     */
    public static function registerActiveModules(): void
    {
        try {
            $rows = ConnectionManager::get('default')->execute(
                'SELECT DISTINCT lp.component_key, m.version, lp.domain '
                . 'FROM language_packs lp JOIN modules m ON m.module_key = lp.component_key '
                . "WHERE m.status = 'active' AND lp.version = m.version",
            )->fetchAll('assoc');
        } catch (Throwable) {
            return;
        }
        foreach ($rows as $r) {
            self::register((string)$r['domain'], (string)$r['component_key'], (string)$r['version']);
        }
    }

    /** @return array<string, mixed> */
    private static function load(LanguagePackStore $store, string $key, string $version, string $locale, string $domain): array
    {
        $file = $store->filePath($key, $version, $locale, $domain);
        if (!is_file($file)) {
            return [];
        }
        try {
            return (new PoFileParser())->parse($file);
        } catch (Throwable) {
            return [];
        }
    }
}
