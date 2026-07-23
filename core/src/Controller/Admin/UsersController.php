<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditLogger;
use App\Model\Entity\User;
use App\Service\Admin\AreaLabel;
use App\Service\Identity\PasswordResetService;
use App\Service\Mail\MailService;
use App\Service\Permission\AdminAreaResolver;
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
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        // Explicit tenant predicate on groups IN ADDITION to RLS (dual-role
        // convention): under an RLS-bypassing role a stray cross-tenant
        // membership row must not leak a foreign tenant's group name.
        $users = $conn->execute(
            'SELECT u.id, u.username, u.email, u.status, u.first_name, u.last_name, '
            . '(SELECT string_agg(g.name, \', \' ORDER BY g.name) FROM "groups" g '
            . ' JOIN groups_users gu ON gu.group_id = g.id WHERE gu.user_id = u.id '
            . ' AND g.tenant_id = core.current_tenant()) AS group_names '
            . 'FROM users u WHERE u.tenant_id = core.current_tenant() ORDER BY u.username',
        )->fetchAll('assoc');
        // Group choices for the create form: creating a user REQUIRES a group
        // (no group-less users — without one, no BREAD permission ever applies).
        $groupOptions = array_column($conn->execute(
            'SELECT id, name FROM "groups" WHERE active AND tenant_id = core.current_tenant() ORDER BY name',
        )->fetchAll('assoc'), 'name', 'id');
        $this->set(compact('users', 'user', 'openCreate', 'groupOptions'));
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
        // Admin areas are granted via GROUPS now (no per-user toggle). Show the
        // user's EFFECTIVE areas read-only: the union resolved from their group
        // memberships (all areas for an Administrators-group member).
        $held = (new AdminAreaResolver())->areasFor($id);
        $areas = $conn->execute('SELECT area_key, label FROM admin_areas ORDER BY sort_order')->fetchAll('assoc');
        // Localize module-contributed area labels stored as an i18n key (e.g.
        // ticketing.nav.group), so this raw "areas" card matches the nav/dashboard
        // (which resolve the same nav_group via __d). Plaintext labels pass through.
        foreach ($areas as &$a) {
            $a['held'] = in_array((string)$a['area_key'], $held, true);
            $a['label'] = AreaLabel::localize((string)$a['label']);
        }
        unset($a);
        $groups = $conn->execute(
            'SELECT g.name FROM "groups" g JOIN groups_users gu ON gu.group_id = g.id WHERE gu.user_id = :id ORDER BY g.name',
            ['id' => $id],
        )->fetchAll('assoc');
        // Self-management (deactivate / anonymize / invite+password) is redundant on
        // one's OWN user page — those belong in "My Profile" — and self-deactivate/
        // -anonymize are refused server-side anyway; hide that whole block for self.
        $isSelf = $this->currentUserId() === $id;
        $this->set(compact('user', 'areas', 'groups', 'isSelf'));
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
        // Mandatory group(s) (no group-less users): without a group membership no
        // BREAD permission ever applies to the account. Each chosen group must be an
        // ACTIVE group of the acting admin's OWN tenant — a POSTed foreign group id
        // would otherwise pull the new user into another tenant's group. Multiple
        // groups may be assigned at once.
        $groupIds = $this->validTenantGroups((array)$this->request->getData('group_ids'));
        // Keep the selection on a failed-create re-render: patchEntity drops
        // group_ids (mass-assignment guard, not a users column), so the multiselect
        // would silently reset while every text field is preserved.
        $user->set('group_ids', $groupIds);
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        if ($groupIds === []) {
            $this->Flash->error(__('flash.user.group_required'));
            $this->renderUserList($user, true);

            return null;
        }
        // atomic=false: the request already runs in a transaction
        // (TransactionRlsMiddleware), so save needs no nested one. This also keeps
        // a failing application rule (unique email/username) a clean `false` instead
        // of a nested-transaction rollback that would poison the request transaction.
        if ($users->save($user, ['atomic' => false])) {
            foreach ($groupIds as $gid) {
                $conn->execute(
                    'INSERT INTO groups_users (group_id, user_id) VALUES (:g, :u) ON CONFLICT DO NOTHING',
                    ['g' => $gid, 'u' => (string)$user->get('id')],
                );
            }
            $this->audit()->log('user.create', 'user', (string)$user->id, [
                'newValue' => ['status' => $user->status, 'groups' => $groupIds],
            ]);
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

    public function edit(string $id): ?Response
    {
        if (($deny = $this->denyCrossTenant($id)) !== null) {
            return $deny;
        }
        // Self-management belongs in "My Profile": an admin must NOT edit their OWN
        // account here — neither the fields nor the group memberships — which would
        // otherwise let them strip their own groups (incl. the admin group) and lock
        // themselves out. addGroup()/removeGroup() enforce the same guard server-side.
        if ($id === $this->currentUserId()) {
            $this->Flash->error(__('flash.user.self_edit'));

            return $this->redirect(['action' => 'view', $id]);
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
            // atomic=false: already inside the request transaction; also keeps a
            // unique-rule violation a clean validation failure (see add()).
            if ($users->save($user, ['atomic' => false])) {
                $this->audit()->log('user.update', 'user', $id, ['newValue' => ['username' => $user->get('username')]]);
                $this->Flash->success(__('flash.user.updated'));

                return $this->redirect(['action' => 'view', $id]);
            }
            $this->Flash->error(__('flash.user.update_failed'));
        }
        // The user's current groups (each removable) + the tenant's OTHER active
        // groups (addable) for the inline membership editor.
        [$groups, $availableGroups] = $this->userGroups($id);
        $this->set(compact('user', 'groups', 'availableGroups'));

        return null;
    }

    /** Adds the POSTed group to the user (membership editor on the edit page). */
    public function addGroup(string $id): ?Response
    {
        $this->request->allowMethod('post');
        if (($deny = $this->denyCrossTenant($id)) !== null) {
            return $deny;
        }
        if ($id === $this->currentUserId()) {
            $this->Flash->error(__('flash.user.self_edit'));

            return $this->redirect(['action' => 'view', $id]);
        }
        $valid = $this->validTenantGroups([$this->request->getData('group_id')]);
        if ($valid === []) {
            $this->Flash->error(__('flash.user.group_invalid'));

            return $this->redirect(['action' => 'edit', $id]);
        }
        ConnectionManager::get('default')->execute(
            'INSERT INTO groups_users (group_id, user_id) VALUES (:g, :u) ON CONFLICT DO NOTHING',
            ['g' => $valid[0], 'u' => $id],
        );
        $this->audit()->log('user.group_add', 'user', $id, ['newValue' => ['group' => $valid[0]]]);
        $this->Flash->success(__('flash.user.group_added'));

        return $this->redirect(['action' => 'edit', $id]);
    }

    /** Removes a group from the user; refuses to remove the last one (group is mandatory). */
    public function removeGroup(string $id, string $groupId): ?Response
    {
        $this->request->allowMethod('post');
        if (($deny = $this->denyCrossTenant($id)) !== null) {
            return $deny;
        }
        if ($id === $this->currentUserId()) {
            $this->Flash->error(__('flash.user.self_edit'));

            return $this->redirect(['action' => 'view', $id]);
        }
        if (!$this->isUuid($groupId)) {
            return $this->notFound();
        }
        $conn = ConnectionManager::get('default');
        // No group-less users (mirrors the mandatory group at creation): refuse to
        // remove the user's LAST tenant group.
        $count = (int)$conn->execute(
            'SELECT count(*) FROM groups_users gu JOIN "groups" g ON g.id = gu.group_id '
            . 'WHERE gu.user_id = :u AND g.tenant_id = core.current_tenant()',
            ['u' => $id],
        )->fetch()[0];
        if ($count <= 1) {
            $this->Flash->error(__('flash.user.group_remove_last'));

            return $this->redirect(['action' => 'edit', $id]);
        }
        $conn->execute('DELETE FROM groups_users WHERE user_id = :u AND group_id = :g', ['u' => $id, 'g' => $groupId]);
        $this->audit()->log('user.group_remove', 'user', $id, ['oldValue' => ['group' => $groupId]]);
        $this->Flash->success(__('flash.user.group_removed'));

        return $this->redirect(['action' => 'edit', $id]);
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
     * The user's current group memberships (id+name, each removable) and the tenant's
     * OTHER active groups still available to add — both scoped to the acting admin's
     * tenant (a removed group reappears in the available list, and vice versa).
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>}
     */
    private function userGroups(string $id): array
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        $current = $conn->execute(
            'SELECT g.id, g.name FROM "groups" g JOIN groups_users gu ON gu.group_id = g.id '
            . 'WHERE gu.user_id = :id AND g.tenant_id = core.current_tenant() ORDER BY g.name',
            ['id' => $id],
        )->fetchAll('assoc');
        $held = array_column($current, 'id');
        $available = [];
        $active = $conn->execute(
            'SELECT id, name FROM "groups" WHERE active AND tenant_id = core.current_tenant() ORDER BY name',
        )->fetchAll('assoc');
        foreach ($active as $g) {
            if (!in_array($g['id'], $held, true)) {
                $available[(string)$g['id']] = (string)$g['name'];
            }
        }

        return [$current, $available];
    }

    /**
     * The subset of $ids that are ACTIVE groups of the acting admin's OWN tenant
     * (deduplicated) — a POSTed foreign/inactive group id is silently dropped so it
     * can never pull a user into another tenant's group.
     *
     * @param array<mixed> $ids
     * @return list<string>
     */
    private function validTenantGroups(array $ids): array
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        $out = [];
        foreach ($ids as $gid) {
            $gid = is_string($gid) ? $gid : '';
            if (
                $gid !== '' && !in_array($gid, $out, true) && $this->isUuid($gid)
                && $conn->execute(
                    'SELECT 1 FROM "groups" WHERE id = :g AND active AND tenant_id = core.current_tenant()',
                    ['g' => $gid],
                )->fetch() !== false
            ) {
                $out[] = $gid;
            }
        }

        return $out;
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
        $resolver = new AdminAreaResolver();
        // Only an effective holder of user_group_admin can be its LAST holder. Areas
        // now come from GROUP membership (incl. the Administrators-group wildcard).
        if (!in_array('user_group_admin', $resolver->areasFor($id), true)) {
            return false;
        }
        // "Last admin" is a per-tenant property: no OTHER active user of THIS tenant
        // still effectively holds user_group_admin (a global count would let a tenant
        // strip its own last admin merely because another tenant has one).
        return $resolver->activeHoldersOf('user_group_admin', $id) === 0;
    }
}
