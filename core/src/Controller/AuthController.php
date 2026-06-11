<?php
declare(strict_types=1);

namespace App\Controller;

use App\Auth\LoginThrottle;
use App\Service\Identity\PasswordResetService;
use App\Service\Mail\MailService;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventInterface;

/**
 * Anmeldung/Abmeldung (Step 10). Verdrahtet die lokale Authentifizierung
 * (Step 2) mit Anmeldeschutz (Step 2/4) und dem zweiten Faktor (TOTP).
 *
 * @property \Authentication\Controller\Component\AuthenticationComponent $Authentication
 */
class AuthController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->Authentication->allowUnauthenticated(['login', 'mfa', 'mfaPasskeys', 'setPassword', 'forgotPassword']);
    }

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->viewBuilder()->setLayout('login');
    }

    public function login()
    {
        // Aktive SSO-Provider für die Login-Auswahl (P06); fehlertolerant, damit
        // ein SSO-Problem den lokalen Login nie blockiert (Break-Glass).
        try {
            $this->set('ssoProviders', (new \App\Service\Auth\Sso\SsoService())->activeProviders());
        } catch (\Throwable) {
            $this->set('ssoProviders', []);
        }
        // Mandantenspezifisches Branding (aus dem pre-auth host-aufgelösten Mandanten).
        try {
            $this->set('tenantBranding', (new \App\Service\Tenant\TenantService())->currentBranding());
        } catch (\Throwable) {
            $this->set('tenantBranding', null);
        }

        $result = $this->Authentication->getResult();
        $throttle = new LoginThrottle();
        $username = (string)($this->request->getData('username') ?? '');

        // Echte Sperre: Ist das Konto gedrosselt, wird die Anmeldung verweigert —
        // AUCH bei korrektem Passwort. Sonst prüft die Authentication-Middleware
        // die Zugangsdaten bei jedem POST und der `isBlocked`-Zweig würde nur die
        // Fehlermeldung unterdrücken, die Anmeldung aber durchlassen (wirkungslose
        // Drosselung, Brute-Force-Schutz ausgehebelt).
        if ($this->request->is('post') && $username !== '' && $throttle->isBlocked($username)) {
            $this->Flash->error(__('flash.auth.throttled'));

            return null;
        }

        if ($result !== null && $result->isValid()) {
            // MFA-Gate (zweiter Faktor): Das Passwort allein schließt die
            // Anmeldung NICHT ab, wenn TOTP aktiv ist — die von der Middleware
            // persistierte Identity wird wieder entfernt und erst nach gültigem
            // Code (mfa()) neu gesetzt. SSO-Logins laufen separat (IdP-MFA).
            $identity = $result->getData();
            $userId = (string)($identity['id'] ?? '');
            $mfa = new \App\Service\Security\MfaService();
            if ($userId !== '' && $mfa->enabled($userId)) {
                $target = $this->Authentication->getLoginRedirect() ?? '/admin';
                $this->Authentication->logout();
                $this->request->getSession()->write('Mfa.pending', [
                    'id' => $userId,
                    'username' => (string)($identity['username'] ?? $username),
                    'target' => $target,
                    'expires' => time() + 300, // 5-Minuten-Fenster für den 2. Faktor
                ]);

                return $this->redirect('/login/mfa');
            }

            if ($username !== '') {
                $throttle->clear($username);
            }
            // Session-Fixation-Schutz: nach erfolgreicher Anmeldung die Session-ID
            // erneuern, damit eine vor dem Login fixierte ID nicht authentifiziert wird.
            $this->request->getSession()->renew();
            $target = $this->Authentication->getLoginRedirect() ?? '/admin';

            // MFA-Pflicht (Betreiber-Setting): ohne eingerichtetes TOTP direkt
            // in die Einrichtung leiten (AppController erzwingt das zusätzlich).
            if ($mfa->required()) {
                $this->Flash->error(__('flash.mfa.setup_required'));

                return $this->redirect('/mfa');
            }

            return $this->redirect($target);
        }

        if ($this->request->is('post')) {
            if ($username !== '') {
                $throttle->recordFailure($username, $this->request->clientIp() ?: null);
            }
            $this->Flash->error(__('flash.auth.invalid'));
        }

        return null;
    }

    /**
     * Zweiter Faktor nach gültigem Passwort: GET zeigt das Code-Formular,
     * POST prüft TOTP- oder Recovery-Code. Die Anmeldung wird erst hier
     * abgeschlossen (Identity gesetzt + Session erneuert). Fehlversuche
     * laufen über dieselbe Drosselung wie der Passwort-Login.
     *
     * @return \Cake\Http\Response|null
     */
    public function mfa()
    {
        $session = $this->request->getSession();
        /** @var array{id:string,username:string,target:string,expires:int}|null $pending */
        $pending = $session->read('Mfa.pending');
        if (!is_array($pending) || (int)($pending['expires'] ?? 0) < time()) {
            $session->delete('Mfa.pending');
            $this->Flash->error(__('flash.mfa.expired'));

            return $this->redirect('/login');
        }

        $this->set('hasPasskeys', (new \App\Service\Security\WebAuthnService())->hasCredentials((string)$pending['id']));

        if (!$this->request->is('post')) {
            return null;
        }

        $username = (string)$pending['username'];
        $throttle = new LoginThrottle();
        if ($throttle->isBlocked($username)) {
            $this->Flash->error(__('flash.auth.throttled'));

            return null;
        }

        // Zweiter Faktor: Passkey-Assertion (JS-befüllte Felder) ODER TOTP-/Recovery-Code.
        $verified = false;
        if ((string)$this->request->getData('credential_id') !== '') {
            $challenge = (string)($session->read('Mfa.passkey_challenge') ?? '');
            $session->delete('Mfa.passkey_challenge');
            $verified = $challenge !== '' && (new \App\Service\Security\WebAuthnService())->verifyAssertion(
                (string)$pending['id'],
                (string)$this->request->getData('credential_id'),
                (string)$this->request->getData('client_data'),
                (string)$this->request->getData('auth_data'),
                (string)$this->request->getData('signature'),
                $challenge,
                (string)$this->request->getUri()->getHost(),
            );
        } else {
            $code = (string)$this->request->getData('code');
            $verified = (new \App\Service\Security\MfaService())->verify((string)$pending['id'], $code);
        }
        if (!$verified) {
            $throttle->recordFailure($username, $this->request->clientIp() ?: null);
            $this->Flash->error(__('flash.mfa.invalid'));

            return null;
        }

        // Faktor 2 ok -> Anmeldung abschließen (wie der SSO-Pfad: ORM-Entity).
        $session->delete('Mfa.pending');
        $throttle->clear($username);
        $user = $this->fetchTable('Users')->find()
            ->where(['id' => (string)$pending['id'], 'status' => 'active'])
            ->first();
        if ($user === null) {
            return $this->redirect('/login');
        }
        $session->renew();
        $this->Authentication->setIdentity($user);

        return $this->redirect((string)$pending['target'] ?: '/admin');
    }

    /**
     * JSON-Optionen für `navigator.credentials.get()` im Challenge-Schritt
     * (nur mit gültigem Pending aus dem Passwort-Schritt).
     */
    public function mfaPasskeys(): \Cake\Http\Response
    {
        $session = $this->request->getSession();
        /** @var array{id:string,expires:int}|null $pending */
        $pending = $session->read('Mfa.pending');
        if (!is_array($pending) || (int)($pending['expires'] ?? 0) < time()) {
            return $this->response->withStatus(410)->withType('application/json')
                ->withStringBody((string)json_encode(['error' => 'expired']));
        }
        $challenge = \App\Service\Security\WebAuthnService::challenge();
        $session->write('Mfa.passkey_challenge', $challenge);
        $options = (new \App\Service\Security\WebAuthnService())->assertionOptions(
            (string)$pending['id'],
            (string)$this->request->getUri()->getHost(),
            $challenge,
        );

        return $this->response->withType('application/json')->withStringBody((string)json_encode($options));
    }

    public function logout()
    {
        $this->Authentication->logout();
        $this->Flash->success(__('flash.auth.loggedout'));

        return $this->redirect('/login');
    }

    /**
     * Self-Service „Passwort vergessen" (Kap. 27.2/27.15): erzeugt einen
     * Reset-Token und versendet den Link per E-Mail (Core-MailService). Die
     * Antwort ist immer neutral (keine Konto-Enumeration).
     */
    public function forgotPassword()
    {
        if ($this->request->is('post')) {
            $q = trim((string)$this->request->getData('identifier'));
            if ($q !== '') {
                $row = ConnectionManager::get('default')->execute(
                    'SELECT id, username, email FROM users '
                    . 'WHERE (lower(username) = lower(:q) OR lower(email) = lower(:q)) '
                    . "AND status IN ('active', 'invited') LIMIT 1",
                    ['q' => $q],
                )->fetch('assoc');
                if ($row !== false) {
                    $token = (new PasswordResetService())->create((string)$row['id'], 'reset', 72);
                    $url = (string)$this->request->getUri()->withPath('/set-password')->withQuery('token=' . $token);
                    (new MailService())->sendPasswordReset((string)$row['email'], (string)$row['username'], $url);
                }
            }
            $this->Flash->success(__('flash.auth.reset_sent'));

            return $this->redirect('/login');
        }

        return null;
    }

    /**
     * Öffentliches Setzen des Passworts per Einladungs-/Reset-Token (Kap.
     * 27.2/27.15). GET zeigt das Formular, POST löst den Token ein.
     */
    public function setPassword()
    {
        $token = (string)($this->request->getQuery('token') ?? $this->request->getData('token') ?? '');
        $service = new PasswordResetService();
        $this->set('token', $token);
        $this->set('minLength', $service->minPasswordLength());

        if ($this->request->is('post')) {
            $password = (string)$this->request->getData('password');
            $confirm = (string)$this->request->getData('password_confirm');
            if ($password !== $confirm) {
                $this->Flash->error(__('flash.auth.pw_mismatch'));

                return null;
            }
            $error = $service->redeem($token, $password);
            if ($error !== null) {
                $this->Flash->error($error);

                return null;
            }
            $this->Flash->success(__('flash.auth.pw_set'));

            return $this->redirect('/login');
        }

        return null;
    }
}
