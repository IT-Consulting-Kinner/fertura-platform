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
 * (Step 2) mit Anmeldeschutz (Step 2/4).
 */
class AuthController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->Authentication->allowUnauthenticated(['login', 'setPassword', 'forgotPassword']);
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

        $result = $this->Authentication->getResult();
        $throttle = new LoginThrottle();

        if ($result !== null && $result->isValid()) {
            $username = (string)($this->request->getData('username') ?? '');
            if ($username !== '') {
                $throttle->clear($username);
            }
            $target = $this->Authentication->getLoginRedirect() ?? '/admin';

            return $this->redirect($target);
        }

        if ($this->request->is('post')) {
            $username = (string)$this->request->getData('username');
            if ($username !== '' && $throttle->isBlocked($username)) {
                $this->Flash->error(__('flash.auth.throttled'));
            } else {
                if ($username !== '') {
                    $throttle->recordFailure($username, $this->request->clientIp());
                }
                $this->Flash->error(__('flash.auth.invalid'));
            }
        }

        return null;
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
