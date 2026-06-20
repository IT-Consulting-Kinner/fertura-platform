<?php
declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\Db;
use App\Service\I18n\LocaleCookie;
use App\Service\Settings\SettingsManager;
use Cake\Http\Response;

/**
 * Persistent language switch (i18n-7, E44).
 *
 * Writes the selected (enabled) locale into the session **and** — for
 * authenticated users — persistently as `user.locale`. Then redirects back to
 * the originating page. Anonymous users rely on the session-only switch via `?lang=…`.
 */
class LocaleController extends AppController
{
    public function change(): ?Response
    {
        $this->request->allowMethod('post');
        $lang = (string)$this->request->getData('lang');
        $enabled = array_map('strval', (array)(new SettingsManager())->get('core', 'locale.enabled', ['en_US', 'de_DE']));

        $referer = $this->request->referer();
        $response = $this->redirect($referer ?: '/admin');

        if (in_array($lang, $enabled, true)) {
            $this->request->getSession()->write('locale', $lang);
            $identity = $this->identity();
            if ($identity !== null) {
                // Own preference – controlled + validated. Via the privileged
                // connection to avoid RLS self-update policy concerns (harmless
                // single column).
                Db::privileged()->execute(
                    'UPDATE core.users SET locale = :l WHERE id = :u',
                    ['l' => $lang, 'u' => (string)$identity->getIdentifier()],
                );
            }
            // Also remember the choice in the cookie read by LocaleMiddleware, so
            // it survives a logout (the login mask then keeps the chosen language).
            $response = $response->withCookie(LocaleCookie::make($lang));
        }

        return $response;
    }
}
