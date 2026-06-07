<?php
declare(strict_types=1);

namespace App\I18n;

use Cake\I18n\I18n;
use Cake\I18n\MessagesFileLoader;
use Cake\I18n\Package;

/**
 * Registriert für eine Übersetzungs-Domain einen Loader, der **Englisch als
 * Basis** lädt und die gewählte Locale darüber legt (E37/E39).
 *
 * CakePHPs eingebauter Fallback ist nur ein *Domain*-Fallback (Custom-Domain →
 * `default`) innerhalb derselben Locale — **kein** Locale-Fallback. Da im Fertura-
 * Modell jede Komponente einen vollständigen Englisch-Katalog mitbringt, ist das
 * Mergen von Englisch als Basis der robuste, vorhersagbare Weg: Ein in der
 * gewählten Sprache fehlender Schlüssel zeigt automatisch den englischen Text
 * statt des rohen Schlüssels.
 */
class EnglishFallbackLoader
{
    public const BASE_LOCALE = 'en_US';

    /**
     * Registriert den Merge-Loader für eine Domain (z. B. `default` = Core,
     * später `<module_key>` je Modul).
     */
    public static function register(string $domain): void
    {
        I18n::config($domain, static function (string $name, string $locale) use ($domain): Package {
            $base = (new MessagesFileLoader($name, self::BASE_LOCALE))();
            if (!$base instanceof Package) {
                $base = new Package();
            }
            // sprintf-Platzhalter (%s) statt ICU ({0}); die Core-Kataloge nutzen %s.
            $base->setFormatter('sprintf');
            if ($locale !== self::BASE_LOCALE) {
                $localePkg = (new MessagesFileLoader($name, $locale))();
                if ($localePkg instanceof Package) {
                    // gewählte Locale überschreibt Englisch (fehlt ein Schlüssel,
                    // bleibt der englische Basistext).
                    $base->addMessages($localePkg->getMessages());
                }
                // Zusätzlich: nachgeladene Core-Sprachpakete aus dem Store
                // (i18n-5/6) – z. B. eine später eingespielte Sprache. Versions-
                // Gate via LocaleResolver; überschreibt die mitgelieferten Texte.
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
            $resolver = new \App\Service\I18n\LocaleResolver();
            $res = $resolver->resolveVersion('core', \App\Application::CORE_VERSION, $locale);
            if ($res === null) {
                return [];
            }
            $file = (new \App\Service\I18n\LanguagePackStore())->filePath('core', $res['version'], $locale, $domain);
            if (!is_file($file)) {
                return [];
            }

            return (new \Cake\I18n\Parser\PoFileParser())->parse($file);
        } catch (\Throwable) {
            return [];
        }
    }
}
