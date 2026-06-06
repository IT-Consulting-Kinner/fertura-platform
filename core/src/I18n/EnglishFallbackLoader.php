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
        I18n::config($domain, static function (string $name, string $locale): Package {
            $base = (new MessagesFileLoader($name, self::BASE_LOCALE))();
            if (!$base instanceof Package) {
                $base = new Package();
            }
            if ($locale !== self::BASE_LOCALE) {
                $localePkg = (new MessagesFileLoader($name, $locale))();
                if ($localePkg instanceof Package) {
                    // gewählte Locale überschreibt Englisch (fehlt ein Schlüssel,
                    // bleibt der englische Basistext).
                    $base->addMessages($localePkg->getMessages());
                }
            }

            return $base;
        });
    }
}
