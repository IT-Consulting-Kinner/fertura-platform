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
 * Registers translation domains that load their catalogs from the **Managed
 * Locale Store** (i18n-4) — English as the base, selected locale on top (E37/E39).
 *
 * Unlike {@see EnglishFallbackLoader} (core domain `default` from
 * resources/locales), module/extension domains read from the store for the
 * respective active component version.
 */
class StoreLocaleLoader
{
    public const BASE_LOCALE = 'en_US';

    public static function register(string $domain, string $componentKey, string $activeVersion): void
    {
        I18n::config($domain, static function (string $name, string $locale) use ($componentKey, $activeVersion, $domain): Package {
            $store = new LanguagePackStore();
            $resolver = new \App\Service\I18n\LocaleResolver();

            // English base (version gate: exact > same-major > none).
            $en = $resolver->resolveVersion($componentKey, $activeVersion, self::BASE_LOCALE);
            $messages = $en !== null ? self::load($store, $componentKey, $en['version'], self::BASE_LOCALE, $domain) : [];

            if ($locale !== self::BASE_LOCALE) {
                $loc = $resolver->resolveVersion($componentKey, $activeVersion, $locale);
                if ($loc !== null) {
                    $messages = array_merge($messages, self::load($store, $componentKey, $loc['version'], $locale, $domain));
                }
                // Major mismatch (resolveVersion null) → no overlay → English.
            }

            return new Package('sprintf', null, $messages);
        });
    }

    /**
     * Registers the domains of all active modules/extensions whose language
     * files are present in the store in the version matching the active
     * version. Fault-tolerant (DB/store may not yet be available).
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
