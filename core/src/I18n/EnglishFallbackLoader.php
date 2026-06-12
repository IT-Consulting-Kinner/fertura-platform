<?php
declare(strict_types=1);

namespace App\I18n;

use App\Application;
use App\Service\I18n\LanguagePackStore;
use App\Service\I18n\LocaleResolver;
use Cake\I18n\I18n;
use Cake\I18n\MessagesFileLoader;
use Cake\I18n\Package;
use Cake\I18n\Parser\PoFileParser;
use Throwable;

/**
 * Registers, for a translation domain, a loader that loads **English as the
 * base** and overlays the selected locale on top of it (E37/E39).
 *
 * CakePHP's built-in fallback is only a *domain* fallback (custom domain →
 * `default`) within the same locale — **not** a locale fallback. Since in the
 * Fertura model every component ships a complete English catalog, merging
 * English as the base is the robust, predictable approach: a key missing in the
 * selected language automatically shows the English text instead of the raw key.
 */
class EnglishFallbackLoader
{
    public const BASE_LOCALE = 'en_US';

    /**
     * Registers the merge loader for a domain (e.g. `default` = core,
     * later `<module_key>` per module).
     */
    public static function register(string $domain): void
    {
        I18n::config($domain, static function (string $name, string $locale) use ($domain): Package {
            $base = (new MessagesFileLoader($name, self::BASE_LOCALE))();
            if (!$base instanceof Package) {
                $base = new Package();
            }
            // sprintf placeholders (%s) instead of ICU ({0}); the core catalogs use %s.
            $base->setFormatter('sprintf');
            if ($locale !== self::BASE_LOCALE) {
                $localePkg = (new MessagesFileLoader($name, $locale))();
                if ($localePkg instanceof Package) {
                    // The selected locale overrides English (if a key is
                    // missing, the English base text remains).
                    $base->addMessages($localePkg->getMessages());
                }
                // Additionally: core language packs loaded later from the store
                // (i18n-5/6) – e.g. a language installed afterwards. Version-
                // gated via LocaleResolver; overrides the bundled texts.
                $store = self::coreStorePack($domain, $locale);
                if ($store !== []) {
                    $base->addMessages($store);
                }
            }

            return $base;
        });
    }

    /** @return array<string, mixed> */
    private static function coreStorePack(string $domain, string $locale): array
    {
        try {
            $resolver = new LocaleResolver();
            $res = $resolver->resolveVersion('core', Application::CORE_VERSION, $locale);
            if ($res === null) {
                return [];
            }
            $file = (new LanguagePackStore())->filePath('core', $res['version'], $locale, $domain);
            if (!is_file($file)) {
                return [];
            }

            return (new PoFileParser())->parse($file);
        } catch (Throwable) {
            return [];
        }
    }
}
