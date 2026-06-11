<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Model\Entity\User;
use App\Service\Identity\PasswordResetService;
use App\Service\Mail\MailService;
use Cake\Datasource\ConnectionManager;

/**
 * Benutzerverwaltung (Administrationsbereich „Benutzer- und Gruppenverwaltung").
 */
class UsersController extends AdminController
{
    protected ?string $requiredArea = 'user_group_admin';

    private function audit()
    {
        return new \App\Audit\AuditLogger();
    }

    public function index(): void
    {
        $users = ConnectionManager::get('default')->execute(
            'SELECT id, username, email, status, first_name, last_name FROM users ORDER BY username',
        )->fetchAll('assoc');
        $this->set(compact('users'));
    }

    public function view(string $id): void
    {
        if (!$this->isUuid($id)) {
            $this->notFound();

            return;
        }
        $conn = ConnectionManager::get('default');
        $user = $conn->execute('SELECT * FROM users WHERE id = :id', ['id' => $id])->fetch('assoc');
        if ($user === false) {
            $this->Flash->error(__('flash.user.not_found'));
            $this->redirect(['action' => 'index']);

            return;
        }
        $areas = $conn->execute(
            'SELECT a.area_key, a.label, (ua.user_id IS NOT NULL) AS held FROM admin_areas a '
            . 'LEFT JOIN user_admin_areas ua ON ua.admin_area_key = a.area_key AND ua.user_id = :id ORDER BY a.sort_order',
            ['id' => $id],
        )->fetchAll('assoc');
        $groups = $conn->execute(
            'SELECT g.name FROM "groups" g JOIN groups_users gu ON gu.group_id = g.id WHERE gu.user_id = :id ORDER BY g.name',
            ['id' => $id],
        )->fetchAll('assoc');
        $this->set(compact('user', 'areas', 'groups'));
    }

    public function add()
    {
        $users = $this->fetchTable('Users');
        $user = $users->newEmptyEntity();
        if ($this->request->is('post')) {
            $user = $users->patchEntity($user, $this->request->getData());
            $user->set('status', User::STATUS_INVITED);
            if ($users->save($user)) {
                $this->audit()->log('user.create', 'user', (string)$user->id, ['newValue' => ['status' => $user->status]]);
                $this->Flash->success(__('flash.user.created'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('flash.user.create_failed'));
        }
        $this->set(compact('user'));

        return null;
    }

    public function setStatus(string $id, string $status)
    {
        $this->request->allowMethod('post');
        if (!$this->isUuid($id)) {
            return $this->notFound();
        }
        if (!in_array($status, [User::STATUS_ACTIVE, User::STATUS_DISABLED], true)) {
            $this->Flash->error(__('flash.user.invalid_status'));

            return $this->redirect(['action' => 'index']);
        }
        $conn = ConnectionManager::get('default');

        if ($status === User::STATUS_DISABLED) {
            // Selbst-Aussperr-Schutz (Kap. 27.14/27.15).
            if ($id === $this->currentUserId()) {
                $this->Flash->error(__('flash.user.self_deactivate'));

                return $this->redirect(['action' => 'view', $id]);
            }
            if ($this->isLastUserGroupAdmin($id)) {
                $this->Flash->error(__('flash.user.last_admin_deactivate'));

                return $this->redirect(['action' => 'view', $id]);
            }
        }
        if ($status === User::STATUS_ACTIVE && !$this->hasPassword($id)) {
            $this->Flash->error(__('flash.user.no_password'));

            return $this->redirect(['action' => 'view', $id]);
        }

        $conn->execute(
            'UPDATE users SET status = :s, deactivated_at = CASE WHEN :s = \'disabled\' THEN now() ELSE NULL END WHERE id = :id',
            ['s' => $status, 'id' => $id],
        );
        $this->audit()->log($status === 'active' ? 'user.activate' : 'user.deactivate', 'user', $id, ['newValue' => ['status' => $status]]);
        $this->Flash->success(__('flash.user.status_updated'));

        return $this->redirect(['action' => 'view', $id]);
    }

    public function toggleArea(string $id, string $area)
    {
        $this->request->allowMethod('post');
        if (!$this->isUuid($id)) {
            return $this->notFound();
        }
        $conn = ConnectionManager::get('default');
        $exists = $conn->execute('SELECT 1 FROM user_admin_areas WHERE user_id = :u AND admin_area_key = :a', ['u' => $id, 'a' => $area])->fetch();
        if ($exists) {
            // Selbst-Aussperr-Schutz: letzten user_group_admin-Bereich nicht entziehen.
            if ($area === 'user_group_admin' && $this->isLastUserGroupAdmin($id)) {
                $this->Flash->error(__('flash.user.last_admin_revoke'));

                return $this->redirect(['action' => 'view', $id]);
            }
            $conn->execute('DELETE FROM user_admin_areas WHERE user_id = :u AND admin_area_key = :a', ['u' => $id, 'a' => $area]);
            $this->audit()->log('admin_access.revoke', 'user', $id, ['newValue' => ['area' => $area]]);
        } else {
            $conn->execute('INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a) ON CONFLICT DO NOTHING', ['u' => $id, 'a' => $area]);
            $this->audit()->log('admin_access.grant', 'user', $id, ['newValue' => ['area' => $area]]);
        }
        $this->Flash->success(__('flash.user.area_updated'));

        return $this->redirect(['action' => 'view', $id]);
    }

    public function edit(string $id)
    {
        $users = $this->fetchTable('Users');
        $user = $users->find()->where(['id' => $id])->first();
        if ($user === null || $user->get('status') === User::STATUS_ANONYMIZED) {
            $this->Flash->error(__('flash.user.not_available'));

            return $this->redirect(['action' => 'index']);
        }
        if ($this->request->is(['post', 'put', 'patch'])) {
            $user = $users->patchEntity($user, $this->request->getData(), [
                'fields' => ['username', 'email', 'first_name', 'last_name'],
            ]);
            if ($users->save($user)) {
                $this->audit()->log('user.update', 'user', $id, ['newValue' => ['username' => $user->get('username')]]);
                $this->Flash->success(__('flash.user.updated'));

                return $this->redirect(['action' => 'view', $id]);
            }
            $this->Flash->error(__('flash.user.update_failed'));
        }
        $this->set(compact('user'));

        return null;
    }

    /**
     * Erzeugt einen Einladungs-/Passwort-Setz-Link (Kap. 27.2/27.15) und versendet
     * ihn per E-Mail (Core-MailService). Der Link wird zusätzlich angezeigt
     * (Fallback, falls kein Mailversand möglich ist).
     */
    public function invite(string $id)
    {
        $this->request->allowMethod('post');
        if (!$this->isUuid($id)) {
            return $this->notFound();
        }
        $conn = ConnectionManager::get('default');
        $row = $conn->execute('SELECT username, email, status FROM users WHERE id = :id', ['id' => $id])->fetch('assoc');
        if ($row === false || $row['status'] === User::STATUS_ANONYMIZED) {
            $this->Flash->error(__('flash.user.not_available'));

            return $this->redirect(['action' => 'index']);
        }
        $actor = $this->identity()?->getIdentifier();
        $token = (new PasswordResetService())->create($id, 'invite', 72, $actor !== null ? (string)$actor : null);
        $url = (string)$this->request->getUri()->withPath('/set-password')->withQuery('token=' . $token);

        $sent = (new MailService())->sendInvitation((string)$row['email'], (string)$row['username'], $url);
        if ($sent) {
            $this->Flash->success(__('flash.user.invite_sent', h($row['email']), $url));
        } else {
            $this->Flash->success(__('flash.user.invite_link', $url));
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /** Setzt das Passwort direkt (Administrator) und aktiviert „invited"-Benutzer. */
    public function setPassword(string $id)
    {
        $this->request->allowMethod('post');
        $password = (string)$this->request->getData('password');
        $service = new PasswordResetService();
        $min = $service->minPasswordLength();
        if (strlen($password) < $min) {
            $this->Flash->error(__('flash.user.password_too_short', $min));

            return $this->redirect(['action' => 'view', $id]);
        }
        $users = $this->fetchTable('Users');
        $user = $users->find()->where(['id' => $id])->first();
        if ($user === null || $user->get('status') === User::STATUS_ANONYMIZED) {
            $this->Flash->error(__('flash.user.not_available'));

            return $this->redirect(['action' => 'index']);
        }
        $user->setPassword($password);
        if ($user->get('status') === User::STATUS_INVITED) {
            $user->set('status', User::STATUS_ACTIVE);
        }
        $users->save($user, ['checkRules' => false]);
        $this->audit()->log('user.password_set', 'user', $id, ['newValue' => ['by' => 'admin', 'status' => $user->get('status')]]);
        $this->Flash->success(__('flash.user.password_set'));

        return $this->redirect(['action' => 'view', $id]);
    }

    public function anonymize(string $id)
    {
        $this->request->allowMethod('post');
        if (!$this->isUuid($id)) {
            return $this->notFound();
        }
        if ($id === $this->currentUserId()) {
            $this->Flash->error(__('flash.user.self_anonymize'));

            return $this->redirect(['action' => 'view', $id]);
        }
        if ($this->isLastUserGroupAdmin($id)) {
            $this->Flash->error(__('flash.user.last_admin_anonymize'));

            return $this->redirect(['action' => 'view', $id]);
        }
        $users = $this->fetchTable('Users');
        $user = $users->find()->where(['id' => $id])->first();
        if ($user !== null && $users->anonymize($user)) {
            $this->Flash->success(__('flash.user.anonymized'));
        } else {
            $this->Flash->error(__('flash.user.anonymize_failed'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /** Fehlgeformte ID (UUID-Guard): wie unbekannten Benutzer behandeln. */
    private function notFound(): ?\Cake\Http\Response
    {
        $this->Flash->error(__('flash.user.not_found'));

        return $this->redirect(['action' => 'index']);
    }

    private function currentUserId(): ?string
    {
        $id = $this->identity()?->getIdentifier();

        return $id !== null ? (string)$id : null;
    }

    private function hasPassword(string $id): bool
    {
        $row = ConnectionManager::get('default')->execute(
            'SELECT password_hash FROM users WHERE id = :id',
            ['id' => $id],
        )->fetch('assoc');

        return $row !== false && $row['password_hash'] !== null && $row['password_hash'] !== '';
    }

    /**
     * Hält dieser (aktive) Benutzer den Bereich user_group_admin und wäre er der
     * letzte aktive Träger? Dann darf er nicht entzogen/deaktiviert werden.
     */
    private function isLastUserGroupAdmin(string $id): bool
    {
        $conn = ConnectionManager::get('default');
        $holds = $conn->execute(
            "SELECT 1 FROM user_admin_areas WHERE user_id = :id AND admin_area_key = 'user_group_admin'",
            ['id' => $id],
        )->fetch();
        if ($holds === false) {
            return false;
        }
        $others = (int)$conn->execute(
            "SELECT count(DISTINCT ua.user_id) FROM user_admin_areas ua JOIN users u ON u.id = ua.user_id "
            . "WHERE ua.admin_area_key = 'user_group_admin' AND u.status = 'active' AND ua.user_id <> :id",
            ['id' => $id],
        )->fetch()[0];

        return $others === 0;
    }
}
