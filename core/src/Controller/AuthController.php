<?php
declare(strict_types=1);

namespace App\Controller;

use App\Auth\LoginThrottle;
use App\Service\Auth\Sso\SsoService;
use App\Service\Identity\PasswordResetService;
use App\Service\Mail\MailService;
use App\Service\Security\MfaService;
use App\Service\Security\WebAuthnService;
use App\Service\Tenant\TenantService;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use Throwable;

/**
 * Login/logout (Step 10). Wires local authentication (Step 2) together with
 * login protection (Step 2/4) and the second factor (TOTP).
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

    public function login(): ?Response
    {
        // Active SSO providers for the login selection (P06); fault-tolerant so
        // that an SSO problem never blocks the local login (break-glass).
        try {
            $this->set('ssoProviders', (new SsoService())->activeProviders());
        } catch (Throwable) {
            $this->set('ssoProviders', []);
        }
        // Tenant-specific branding (from the pre-auth host-resolved tenant).
        try {
            $this->set('tenantBranding', (new TenantService())->currentBranding());
        } catch (Throwable) {
            $this->set('tenantBranding', null);
        }

        $result = $this->Authentication->getResult();
        $throttle = new LoginThrottle();
        $username = (string)($this->request->getData('username') ?? '');

        // Hard lockout: if the account is throttled, the login is denied —
        // EVEN with a correct password. Otherwise the authentication middleware
        // verifies the credentials on every POST and the `isBlocked` branch would
        // only suppress the error message while still letting the login through
        // (ineffective throttling, brute-force protection defeated).
        if ($this->request->is('post') && $username !== '' && $throttle->isBlocked($username)) {
            $this->Flash->error(__('flash.auth.throttled'));

            return null;
        }

        if ($result !== null && $result->isValid()) {
            // MFA gate (second factor): the password alone does NOT complete the
            // login when TOTP is active — the identity persisted by the middleware
            // is removed again and only re-set after a valid code (mfa()). SSO
            // logins run separately (IdP MFA).
            $identity = $result->getData();
            $userId = (string)($identity['id'] ?? '');
            $mfa = new MfaService();
            if ($userId !== '' && $mfa->enabled($userId)) {
                $target = $this->Authentication->getLoginRedirect() ?? '/admin';
                $this->Authentication->logout();
                $this->request->getSession()->write('Mfa.pending', [
                    'id' => $userId,
                    'username' => (string)($identity['username'] ?? $username),
                    'target' => $target,
                    'expires' => time() + 300, // 5-minute window for the 2nd factor
                ]);

                return $this->redirect('/login/mfa');
            }

            if ($username !== '') {
                $throttle->clear($username);
            }
            // Session fixation protection: after a successful login, renew the
            // session ID so that an ID fixed before login is not authenticated.
            $this->request->getSession()->renew();
            $target = $this->Authentication->getLoginRedirect() ?? '/admin';

            // MFA enforcement (operator setting): without configured TOTP, redirect
            // straight to setup (the AppController additionally enforces this).
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
     * Second factor after a valid password: GET shows the code form,
     * POST verifies the TOTP or recovery code. The login is only completed
     * here (identity set + session renewed). Failed attempts go through the
     * same throttling as the password login.
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

        $this->set('hasPasskeys', (new WebAuthnService())->hasCredentials((string)$pending['id']));

        if (!$this->request->is('post')) {
            return null;
        }

        $username = (string)$pending['username'];
        $throttle = new LoginThrottle();
        if ($throttle->isBlocked($username)) {
            $this->Flash->error(__('flash.auth.throttled'));

            return null;
        }

        // Second factor: passkey assertion (JS-populated fields) OR TOTP/recovery code.
        $verified = false;
        if ((string)$this->request->getData('credential_id') !== '') {
            $challenge = (string)($session->read('Mfa.passkey_challenge') ?? '');
            $session->delete('Mfa.passkey_challenge');
            $verified = $challenge !== '' && (new WebAuthnService())->verifyAssertion(
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
            $verified = (new MfaService())->verify((string)$pending['id'], $code);
        }
        if (!$verified) {
            $throttle->recordFailure($username, $this->request->clientIp() ?: null);
            $this->Flash->error(__('flash.mfa.invalid'));

            return null;
        }

        // Factor 2 ok -> complete the login (like the SSO path: ORM entity).
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
     * JSON options for `navigator.credentials.get()` in the challenge step
     * (only with a valid pending state from the password step).
     */
    public function mfaPasskeys(): Response
    {
        $session = $this->request->getSession();
        /** @var array{id:string,expires:int}|null $pending */
        $pending = $session->read('Mfa.pending');
        if (!is_array($pending) || (int)($pending['expires'] ?? 0) < time()) {
            return $this->response->withStatus(410)->withType('application/json')
                ->withStringBody((string)json_encode(['error' => 'expired']));
        }
        $challenge = WebAuthnService::challenge();
        $session->write('Mfa.passkey_challenge', $challenge);
        $options = (new WebAuthnService())->assertionOptions(
            (string)$pending['id'],
            (string)$this->request->getUri()->getHost(),
            $challenge,
        );

        return $this->response->withType('application/json')->withStringBody((string)json_encode($options));
    }

    public function logout(): ?Response
    {
        $this->Authentication->logout();
        $this->Flash->success(__('flash.auth.loggedout'));

        return $this->redirect('/login');
    }

    /**
     * Self-service "forgot password" (ch. 27.2/27.15): creates a reset token
     * and sends the link by email (Core MailService). The response is always
     * neutral (no account enumeration).
     */
    public function forgotPassword(): ?Response
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
     * Public password setting via invitation/reset token (ch. 27.2/27.15).
     * GET shows the form, POST redeems the token.
     */
    public function setPassword(): ?Response
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
