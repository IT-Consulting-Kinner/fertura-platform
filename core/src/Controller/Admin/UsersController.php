<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditLogger;
use App\Model\Entity\User;
use App\Service\Identity\PasswordResetService;
use App\Service\Mail\MailService;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\Http\Response;

/**
 * User management (admin area "User and Group Management").
 */
class UsersController extends AdminController
{
    protected ?string $requiredArea = 'user_group_admin';

    private function audit(): AuditLogger
    {
        return new AuditLogger();
    }

    public function index(): void
    {
        $this->renderUserList($this->fetchTable('Users')->newEmptyEntity(), false);
    }

    /** Renders the user list plus the inline "create" accordion form. */
    private function renderUserList(EntityInterface $user, bool $openCreate): void
    {
        $users = ConnectionManager::get('default')->execute(
            'SELECT id, username, email, status, first_name, last_name FROM users '
            . 'WHERE tenant_id = core.current_tenant() ORDER BY username',
        )->fetchAll('assoc');
        $this->set(compact('users', 'user', 'openCreate'));
        $this->viewBuilder()->setTemplate('index');
    }

    public function view(string $id): void
    {
        if (!$this->isUuid($id)) {
            $this->notFound();

            return;
        }
        $conn = ConnectionManager::get('default');
        // Tenant isolation: only the acting admin's own tenant (users has no RLS).
        $user = $conn->execute(
            'SELECT * FROM users WHERE id = :id AND tenant_id = core.current_tenant()',
            ['id' => $id],
        )->fetch('assoc');
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

    public function add(): ?Response
    {
        // The "create" form lives inline in the index overview (accordion) and
        // posts here; there is no separate add page anymore, so a GET goes back to
        // the list.
        if (!$this->request->is('post')) {
            return $this->redirect(['action' => 'index']);
        }
        $users = $this->fetchTable('Users');
        $user = $users->patchEntity($users->newEmptyEntity(), $this->request->getData());
        $user->set('status', User::STATUS_INVITED);
        // Create within the acting admin's OWN tenant. Fail CLOSED: if the tenant
        // context is somehow unset, refuse rather than silently default the user into
        // the default (operator) tenant.
        $tid = $this->currentTenantId();
        if ($tid === '') {
            $this->Flash->error(__('flash.user.create_failed'));

            return $this->redirect(['action' => 'index']);
        }
        $user->set('tenant_id', $tid);
        if ($users->save($user)) {
            $this->audit()->log('user.create', 'user', (string)$user->id, ['newValue' => ['status' => $user->status]]);
            $this->Flash->success(__('flash.user.created'));

            return $this->redirect(['action' => 'index']);
        }
        // Re-render the list with the create accordion open and the errors inline.
        $this->Flash->error(__('flash.user.create_failed'));
        $this->renderUserList($user, true);

        return null;
    }

    public function setStatus(string $id, string $status): ?Response
    {
        $this->request->allowMethod('post');
        if (($deny = $this->denyCrossTenant($id)) !== null) {
            return $deny;
        }
        if (!in_array($status, [User::STATUS_ACTIVE, User::STATUS_DISABLED], true)) {
            $this->Flash->error(__('flash.user.invalid_status'));

            return $this->redirect(['action' => 'index']);
        }
        $conn = ConnectionManager::get('default');

        if ($status === User::STATUS_DISABLED) {
            // Self-lockout protection (ch. 27.14/27.15).
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

    public function toggleArea(string $id, string $area): ?Response
    {
        $this->request->allowMethod('post');
        if (($deny = $this->denyCrossTenant($id)) !== null) {
            return $deny;
        }
        $conn = ConnectionManager::get('default');
        $exists = $conn->execute('SELECT 1 FROM user_admin_areas WHERE user_id = :u AND admin_area_key = :a', ['u' => $id, 'a' => $area])->fetch();
        if ($exists) {
            // Self-lockout protection: do not revoke the last user_group_admin area.
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

    public function edit(string $id): ?Response
    {
        if (($deny = $this->denyCrossTenant($id)) !== null) {
            return $deny;
        }
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
     * Creates an invitation / password-set link (ch. 27.2/27.15) and sends it
     * by email (core MailService). The link is also displayed
     * (fallback in case email delivery is not possible).
     */
    public function invite(string $id): ?Response
    {
        $this->request->allowMethod('post');
        if (($deny = $this->denyCrossTenant($id)) !== null) {
            return $deny;
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

    /** Sets the password directly (administrator) and activates "invited" users. */
    public function setPassword(string $id): ?Response
    {
        $this->request->allowMethod('post');
        if (($deny = $this->denyCrossTenant($id)) !== null) {
            return $deny;
        }
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

    public function anonymize(string $id): ?Response
    {
        $this->request->allowMethod('post');
        if (($deny = $this->denyCrossTenant($id)) !== null) {
            return $deny;
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

    /** Malformed ID (UUID guard): treat like an unknown user. */
    private function notFound(): ?Response
    {
        $this->Flash->error(__('flash.user.not_found'));

        return $this->redirect(['action' => 'index']);
    }

    /** The acting admin's own tenant (RLS context); '' when unset (fail-closed). */
    private function currentTenantId(): string
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        $row = $conn->execute('SELECT core.current_tenant() AS t')->fetch('assoc');

        return $row !== false ? (string)($row['t'] ?? '') : '';
    }

    /** Whether $id is a user of the acting admin's OWN tenant (cross-tenant guard). */
    private function userInTenant(string $id): bool
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn->execute(
            'SELECT 1 FROM users WHERE id = :id AND tenant_id = core.current_tenant()',
            ['id' => $id],
        )->fetch() !== false;
    }

    /**
     * Per-user-action guard (tenant isolation): a tenant admin may act ONLY on users
     * of their OWN tenant. A malformed OR out-of-tenant id is treated like an unknown
     * user (redirect) — this prevents both a 22P02 and any cross-tenant mutation, e.g.
     * setting another tenant's (or an operator's) user's password. Returns the
     * redirect response to abort, or null to proceed. `users` has no RLS (pre-auth
     * exception), so this explicit filter — not RLS — is what isolates the table.
     */
    private function denyCrossTenant(string $id): ?Response
    {
        if (!$this->isUuid($id) || !$this->userInTenant($id)) {
            return $this->notFound();
        }

        return null;
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
     * Does this (active) user hold the user_group_admin area and would they be the
     * last active holder? If so, it must not be revoked/deactivated.
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
        // Count holders WITHIN the acting admin's tenant only: with per-tenant user
        // administration, "last admin" is a per-tenant property — a global count would
        // let a tenant remove its own last admin merely because ANOTHER tenant has one.
        $others = (int)$conn->execute(
            'SELECT count(DISTINCT ua.user_id) FROM user_admin_areas ua JOIN users u ON u.id = ua.user_id '
            . "WHERE ua.admin_area_key = 'user_group_admin' AND u.status = 'active' "
            . 'AND u.tenant_id = core.current_tenant() AND ua.user_id <> :id',
            ['id' => $id],
        )->fetch()[0];

        return $others === 0;
    }
}
