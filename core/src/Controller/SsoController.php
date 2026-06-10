<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Auth\Sso\OidcProvider;
use App\Service\Auth\Sso\SamlProvider;
use App\Service\Auth\Sso\SsoService;
use Cake\Event\EventInterface;
use Cake\Routing\Router;
use Throwable;

/**
 * SSO-Login-Flows (Programm Tier-1, P06): OIDC (Authorization-Code + PKCE) und
 * SAML — parallel zur lokalen Anmeldung. Bei Erfolg wird der (provisionierte/
 * verknüpfte) Core-Benutzer als Identität gesetzt (Session).
 */
class SsoController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->Authentication->allowUnauthenticated(['start', 'oidcCallback', 'samlAcs']);
    }

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->viewBuilder()->setLayout('login');
    }

    public function start(string $providerId)
    {
        $provider = (new SsoService())->provider($providerId);
        if ($provider === null || !$provider['active']) {
            $this->Flash->error(__('flash.auth.invalid'));

            return $this->redirect('/login');
        }

        try {
            if ($provider['type'] === 'oidc') {
                $data = (new OidcProvider())->authorizationData($provider, $this->oidcRedirectUri());
                $this->request->getSession()->write('sso_oidc', [
                    'provider' => $providerId,
                    'state' => $data['state'],
                    'nonce' => $data['nonce'],
                    'verifier' => $data['code_verifier'],
                ]);

                return $this->redirect($data['url']);
            }

            // Zufälliger RelayState (vom IdP cookie-unabhängig zurückgespiegelt) als
            // Bindungs-Nonce — NICHT die Provider-ID. Die AuthnRequest-ID wird
            // serverseitig daran gebunden und beim ACS einmalig eingelöst.
            $relayState = bin2hex(random_bytes(16));
            $saml = (new SamlProvider())->loginRequest($provider, $this->samlAcsUrl(), $this->spEntityId(), $relayState);
            (new SsoService())->rememberSamlRequest($relayState, $providerId, (string)$saml['id']);

            return $this->redirect($saml['url']);
        } catch (Throwable $e) {
            $this->Flash->error('SSO-Start fehlgeschlagen: ' . $e->getMessage());

            return $this->redirect('/login');
        }
    }

    public function oidcCallback()
    {
        $session = $this->request->getSession();
        $flow = $session->read('sso_oidc');
        $session->delete('sso_oidc');

        $state = (string)$this->request->getQuery('state');
        $code = (string)$this->request->getQuery('code');
        if (!is_array($flow) || $code === '' || !hash_equals((string)$flow['state'], $state)) {
            $this->Flash->error(__('flash.auth.invalid'));

            return $this->redirect('/login');
        }

        try {
            $provider = (new SsoService())->provider((string)$flow['provider']);
            $identity = (new OidcProvider())->complete(
                (array)$provider,
                $code,
                (string)$flow['verifier'],
                (string)$flow['nonce'],
                $this->oidcRedirectUri(),
            );

            return $this->establish((string)$flow['provider'], $identity);
        } catch (Throwable $e) {
            $this->Flash->error('SSO fehlgeschlagen: ' . $e->getMessage());

            return $this->redirect('/login');
        }
    }

    public function samlAcs()
    {
        $this->request->allowMethod('post');
        $relayState = (string)$this->request->getData('RelayState');
        // onelogin/php-saml liest die Antwort aus den PHP-Superglobals.
        $_POST['SAMLResponse'] = (string)$this->request->getData('SAMLResponse');

        // RelayState **einmalig** einlösen -> gebundener Provider + erwartete
        // Request-ID (cookie-unabhängig; kein Replay). Schlägt das fehl (unbekannt/
        // abgelaufen/verbraucht), wird die Anfrage hart abgelehnt — die Bindung an
        // die AuthnRequest ist damit verpflichtend (nicht still herabstufbar).
        $sso = new SsoService();
        $pending = $sso->consumeSamlRequest($relayState);
        if ($pending === null) {
            $this->Flash->error('SAML-Login fehlgeschlagen: unbekannte oder abgelaufene Anfrage.');

            return $this->redirect('/login');
        }
        $providerId = $pending['provider_id'];
        $expectedId = $pending['request_id'];

        try {
            $provider = $sso->provider($providerId);
            if ($provider === null) {
                throw new \RuntimeException('Unbekannter SSO-Provider.');
            }
            $identity = (new SamlProvider())->processAcs($provider, $this->samlAcsUrl(), $this->spEntityId(), $expectedId);

            return $this->establish($providerId, $identity);
        } catch (Throwable $e) {
            $this->Flash->error('SAML-Login fehlgeschlagen: ' . $e->getMessage());

            return $this->redirect('/login');
        }
    }

    /**
     * @param array{sub:string,email:string,first:?string,last:?string} $identity
     */
    private function establish(string $providerId, array $identity)
    {
        $userId = (new SsoService())->loginExternalUser(
            $providerId,
            $identity['sub'],
            $identity['email'],
            $identity['first'],
            $identity['last'],
            $identity['email_verified'] ?? null,
        );
        $user = $this->fetchTable('Users')->get($userId);
        // Session-Fixation-Schutz: vor dem Setzen der Identität die Session-ID
        // erneuern (der Pre-Auth-Flow lief in derselben Session).
        $this->request->getSession()->renew();
        $this->Authentication->setIdentity($user);
        $this->Flash->success(__('flash.auth.loggedin'));

        return $this->redirect('/admin');
    }

    private function oidcRedirectUri(): string
    {
        return Router::url('/sso/oidc/callback', true);
    }

    private function samlAcsUrl(): string
    {
        return Router::url('/sso/saml/acs', true);
    }

    private function spEntityId(): string
    {
        return Router::url('/sso/saml/metadata', true);
    }
}
