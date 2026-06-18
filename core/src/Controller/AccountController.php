<?php
declare(strict_types=1);

namespace App\Controller;

use App\Audit\AuditLogger;
use App\Model\Entity\User;
use App\Service\Identity\PasswordResetService;
use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\Http\Response;
use Throwable;

/**
 * Self-service account page for ANY authenticated user (ch. 27.2): edit own
 * details (salutation/Anrede, first + last name) and change the own password.
 *
 * Deliberately decoupled from user administration ({@see Admin\UsersController}):
 * it extends {@see AppController} (login required, but NO admin-area gate) and
 * always operates on the *current* identity — a user can never reach or modify
 * another account here. Reachable at /account, independent of any admin role.
 */
class AccountController extends AppController
{
    /** Profile view with the edit + password forms. */
    public function index(): void
    {
        $user = $this->currentUser();
        $this->set('user', $user);
        $this->set('currentUser', $this->identity());
        $this->set('minPassword', (new PasswordResetService())->minPasswordLength());
        $this->viewBuilder()->setLayout('account');
    }

    /** Persists the editable own details (never username/email/status). */
    public function update(): ?Response
    {
        $this->request->allowMethod(['post', 'put', 'patch']);
        $user = $this->currentUser();
        if ($user === null) {
            return $this->redirect('/account');
        }

        $users = $this->fetchTable('Users');
        $user = $users->patchEntity($user, $this->request->getData(), [
            'fields' => ['salutation', 'first_name', 'last_name'],
        ]);
        if ($users->save($user)) {
            $this->audit('user.self_update', (string)$user->get('id'), ['fields' => ['salutation', 'first_name', 'last_name']]);
            $this->Flash->success(__('flash.account.updated'));
        } else {
            $this->Flash->error(__('flash.account.update_failed'));
        }

        return $this->redirect('/account');
    }

    /** Changes the own password after verifying the current one. */
    public function password(): ?Response
    {
        $this->request->allowMethod('post');
        $user = $this->currentUser();
        if ($user === null) {
            return $this->redirect('/account');
        }

        $current = (string)$this->request->getData('current_password');
        $new = (string)$this->request->getData('new_password');
        $confirm = (string)$this->request->getData('confirm_password');

        if (!(new DefaultPasswordHasher())->check($current, (string)$user->get('password_hash'))) {
            $this->Flash->error(__('flash.account.password_current_wrong'));

            return $this->redirect('/account');
        }
        $min = (new PasswordResetService())->minPasswordLength();
        if (strlen($new) < $min) {
            $this->Flash->error(__('flash.account.password_too_short', $min));

            return $this->redirect('/account');
        }
        if ($new !== $confirm) {
            $this->Flash->error(__('flash.account.password_mismatch'));

            return $this->redirect('/account');
        }

        $user->setPassword($new);
        $this->fetchTable('Users')->save($user, ['checkRules' => false]);
        $this->audit('user.self_password', (string)$user->get('id'), ['by' => 'self']);
        $this->Flash->success(__('flash.account.password_changed'));

        return $this->redirect('/account');
    }

    /** The current identity's user row (or null). */
    private function currentUser(): ?User
    {
        $id = $this->identity()?->getIdentifier();
        if (!is_string($id) || $id === '') {
            return null;
        }
        /** @var \App\Model\Entity\User|null $user */
        $user = $this->fetchTable('Users')->find()->where(['id' => $id])->first();

        return $user;
    }

    /**
     * Best-effort audit (a logging hiccup must never block a self-service save).
     *
     * @param array<string,mixed> $newValue
     */
    private function audit(string $action, string $userId, array $newValue): void
    {
        try {
            (new AuditLogger())->log($action, 'user', $userId, ['newValue' => $newValue]);
        } catch (Throwable) {
        }
    }
}
