<?php
declare(strict_types=1);

namespace App\Controller;

use App\Auth\LoginThrottle;
use App\Service\Identity\PasswordResetService;
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
        $this->Authentication->allowUnauthenticated(['login', 'setPassword']);
    }

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->viewBuilder()->setLayout('login');
    }

    public function login()
    {
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
                $this->Flash->error('Zu viele Fehlversuche. Bitte später erneut versuchen.');
            } else {
                if ($username !== '') {
                    $throttle->recordFailure($username, $this->request->clientIp());
                }
                $this->Flash->error('Ungültige Anmeldedaten.');
            }
        }

        return null;
    }

    public function logout()
    {
        $this->Authentication->logout();
        $this->Flash->success('Abgemeldet.');

        return $this->redirect('/login');
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
                $this->Flash->error('Passwörter stimmen nicht überein.');

                return null;
            }
            $error = $service->redeem($token, $password);
            if ($error !== null) {
                $this->Flash->error($error);

                return null;
            }
            $this->Flash->success('Passwort gesetzt. Sie können sich jetzt anmelden.');

            return $this->redirect('/login');
        }

        return null;
    }
}
