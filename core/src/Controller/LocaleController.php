<?php
declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\Db;
use App\Service\Settings\SettingsManager;

/**
 * Persistenter Sprachwechsel (i18n-7, E44).
 *
 * Schreibt die gewählte (aktivierte) Locale in die Session **und** — für
 * angemeldete Nutzer — persistent als `user.locale`. Anschließend zurück zur
 * Herkunftsseite. Anonyme nutzen den reinen Session-Wechsel via `?lang=…`.
 */
class LocaleController extends AppController
{
    public function change()
    {
        $this->request->allowMethod('post');
        $lang = (string)$this->request->getData('lang');
        $enabled = array_map('strval', (array)(new SettingsManager())->get('core', 'locale.enabled', ['en_US', 'de_DE']));

        if (in_array($lang, $enabled, true)) {
            $this->request->getSession()->write('locale', $lang);
            $identity = $this->identity();
            if ($identity !== null) {
                // Eigene Präferenz – kontrolliert + validiert. Über die
                // privilegierte Connection, um RLS-Self-Update-Policy-Fragen zu
                // umgehen (harmlose Einzelspalte).
                Db::privileged()->execute(
                    'UPDATE core.users SET locale = :l WHERE id = :u',
                    ['l' => $lang, 'u' => (string)$identity->getIdentifier()],
                );
            }
        }

        $referer = $this->request->referer();

        return $this->redirect($referer ?: '/admin');
    }
}
