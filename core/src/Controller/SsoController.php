<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Auth\Sso\OidcProvider;
use App\Service\Auth\Sso\SamlProvider;
use App\Service\Auth\Sso\SsoService;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use Cake\Routing\Router;
use RuntimeException;
use Throwable;

/**
 * SSO login flows (program tier-1, P06): OIDC (authorization code + PKCE) and
 * SAML — alongside the local login. On success the (provisioned/linked) core
 * user is set as the identity (session).
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

    public function start(string $providerId): ?Response
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

            // Random RelayState (reflected back by the IdP independent of cookies)
            // as a binding nonce — NOT the provider ID. The AuthnRequest ID is
            // bound to it server-side and redeemed once at the ACS.
            $relayState = bin2hex(random_bytes(16));
            $saml = (new SamlProvider())->loginRequest($provider, $this->samlAcsUrl(), $this->spEntityId(), $relayState);
            (new SsoService())->rememberSamlRequest($relayState, $providerId, (string)$saml['id']);

            return $this->redirect($saml['url']);
        } catch (Throwable $e) {
            $this->Flash->error('SSO-Start fehlgeschlagen: ' . $e->getMessage());

            return $this->redirect('/login');
        }
    }

    public function oidcCallback(): ?Response
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

    public function samlAcs(): ?Response
    {
        $this->request->allowMethod('post');
        $relayState = (string)$this->request->getData('RelayState');
        // onelogin/php-saml reads the response from the PHP superglobals.
        $_POST['SAMLResponse'] = (string)$this->request->getData('SAMLResponse');

        // Redeem the RelayState **once** -> bound provider + expected request ID
        // (cookie-independent; no replay). If that fails (unknown/expired/consumed),
        // the request is hard-rejected — the binding to the AuthnRequest is thus
        // mandatory (cannot be silently downgraded).
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
                throw new RuntimeException('Unbekannter SSO-Provider.');
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
    private function establish(string $providerId, array $identity): ?Response
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
        // Session fixation protection: before setting the identity, renew the
        // session ID (the pre-auth flow ran in the same session).
        $this->request->getSession()->renew();
        $this->Authentication->setIdentity($user);
        // Mark the SSO login: MFA enforcement (security.mfa.required) applies
        // only to LOCAL logins — under federation the IdP enforces the MFA policy.
        $this->request->getSession()->write('Auth.via_sso', true);
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
