<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Model\Entity\User;
use App\Service\Identity\PasswordResetService;
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
        $conn = ConnectionManager::get('default');
        $user = $conn->execute('SELECT * FROM users WHERE id = :id', ['id' => $id])->fetch('assoc');
        if ($user === false) {
            $this->Flash->error('Benutzer nicht gefunden.');
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
                $this->Flash->success('Benutzer angelegt.');

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error('Anlegen fehlgeschlagen.');
        }
        $this->set(compact('user'));

        return null;
    }

    public function setStatus(string $id, string $status)
    {
        $this->request->allowMethod('post');
        if (!in_array($status, [User::STATUS_ACTIVE, User::STATUS_DISABLED], true)) {
            $this->Flash->error('Ungültiger Status.');

            return $this->redirect(['action' => 'index']);
        }
        ConnectionManager::get('default')->execute(
            'UPDATE users SET status = :s, deactivated_at = CASE WHEN :s = \'disabled\' THEN now() ELSE NULL END WHERE id = :id',
            ['s' => $status, 'id' => $id],
        );
        $this->audit()->log($status === 'active' ? 'user.activate' : 'user.deactivate', 'user', $id, ['newValue' => ['status' => $status]]);
        $this->Flash->success('Status aktualisiert.');

        return $this->redirect(['action' => 'view', $id]);
    }

    public function toggleArea(string $id, string $area)
    {
        $this->request->allowMethod('post');
        $conn = ConnectionManager::get('default');
        $exists = $conn->execute('SELECT 1 FROM user_admin_areas WHERE user_id = :u AND admin_area_key = :a', ['u' => $id, 'a' => $area])->fetch();
        if ($exists) {
            $conn->execute('DELETE FROM user_admin_areas WHERE user_id = :u AND admin_area_key = :a', ['u' => $id, 'a' => $area]);
            $this->audit()->log('admin_access.revoke', 'user', $id, ['newValue' => ['area' => $area]]);
        } else {
            $conn->execute('INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a) ON CONFLICT DO NOTHING', ['u' => $id, 'a' => $area]);
            $this->audit()->log('admin_access.grant', 'user', $id, ['newValue' => ['area' => $area]]);
        }
        $this->Flash->success('Administrationsbereich aktualisiert.');

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Erzeugt einen Einladungs-/Passwort-Setz-Link (Kap. 27.2/27.15). Der Link
     * wird dem Administrator angezeigt (E-Mail-Versand = Modul-Scope).
     */
    public function invite(string $id)
    {
        $this->request->allowMethod('post');
        $actor = $this->identity()?->getIdentifier();
        $token = (new PasswordResetService())->create($id, 'invite', 72, $actor !== null ? (string)$actor : null);
        $url = (string)$this->request->getUri()->withPath('/set-password')->withQuery('token=' . $token);
        $this->Flash->success('Einladungslink (72 h gültig): ' . $url);

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
            $this->Flash->error("Passwort muss mindestens $min Zeichen haben.");

            return $this->redirect(['action' => 'view', $id]);
        }
        $users = $this->fetchTable('Users');
        $user = $users->find()->where(['id' => $id])->first();
        if ($user === null || $user->get('status') === User::STATUS_ANONYMIZED) {
            $this->Flash->error('Benutzer nicht verfügbar.');

            return $this->redirect(['action' => 'index']);
        }
        $user->setPassword($password);
        if ($user->get('status') === User::STATUS_INVITED) {
            $user->set('status', User::STATUS_ACTIVE);
        }
        $users->save($user, ['checkRules' => false]);
        $this->audit()->log('user.password_set', 'user', $id, ['newValue' => ['by' => 'admin', 'status' => $user->get('status')]]);
        $this->Flash->success('Passwort gesetzt.');

        return $this->redirect(['action' => 'view', $id]);
    }

    public function anonymize(string $id)
    {
        $this->request->allowMethod('post');
        $users = $this->fetchTable('Users');
        $user = $users->find()->where(['id' => $id])->first();
        if ($user !== null && $users->anonymize($user)) {
            $this->Flash->success('Benutzer irreversibel anonymisiert.');
        } else {
            $this->Flash->error('Anonymisierung fehlgeschlagen.');
        }

        return $this->redirect(['action' => 'index']);
    }
}
