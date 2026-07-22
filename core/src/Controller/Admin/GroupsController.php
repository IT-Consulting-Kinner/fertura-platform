<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditLogger;
use App\Service\Permission\PermissionService;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;

/**
 * Group management including membership and BREAD resource permissions
 * (administration area "User and Group Management").
 */
class GroupsController extends AdminController
{
    protected ?string $requiredArea = 'user_group_admin';

    public function index(): void
    {
        // ?create opens the inline create accordion directly — the target of the
        // "new group" reference link on the user form (create without leaving it).
        $this->renderGroupList($this->request->getQuery('create') !== null);
    }

    /**
     * Active groups of the current tenant as JSON `{options:[{value,label}]}` for the
     * UiKit reference-field refresh on the user form (create a group in another tab,
     * then refresh here without leaving the form). GET, read-only.
     */
    public function options(): Response
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        $rows = $conn->execute(
            'SELECT id, name FROM "groups" WHERE active AND tenant_id = core.current_tenant() ORDER BY name',
        )->fetchAll('assoc');
        $options = array_map(
            static fn(array $r): array => ['value' => (string)$r['id'], 'label' => (string)$r['name']],
            $rows,
        );

        return $this->response
            ->withType('application/json')
            ->withStringBody((string)json_encode(['options' => $options]));
    }

    /** Renders the group list plus the inline "create" accordion form. */
    private function renderGroupList(bool $openCreate): void
    {
        $groups = ConnectionManager::get('default')->execute(
            'SELECT g.id, g.name, g.description, g.active, '
            . '(SELECT count(*) FROM groups_users gu WHERE gu.group_id = g.id) AS member_count '
            . 'FROM "groups" g ORDER BY g.name',
        )->fetchAll('assoc');
        $this->set(compact('groups', 'openCreate'));
        $this->viewBuilder()->setTemplate('index');
    }

    public function add(): ?Response
    {
        // The "create" form lives inline in the index overview (accordion) and
        // posts here; there is no separate add page anymore -> a GET goes back.
        if (!$this->request->is('post')) {
            return $this->redirect(['action' => 'index']);
        }
        $name = trim((string)$this->request->getData('name'));
        $desc = trim((string)$this->request->getData('description')) ?: null;
        if ($name === '') {
            // Re-render the list with the accordion open; FormHelper keeps the input.
            $this->Flash->error(__('flash.group.name_empty'));
            $this->renderGroupList(true);

            return null;
        }
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        // A group name is unique per tenant, case-insensitively (DB index
        // uq_groups_tenant_name_lower). Check up front and warn — a raw INSERT of a
        // duplicate would raise a QueryException (500) AND abort the whole request
        // transaction ({@see \App\Middleware\TransactionRlsMiddleware}). RLS scopes
        // the read to the acting admin's tenant, matching the index's tenant bucket.
        if ($conn->execute('SELECT 1 FROM "groups" WHERE lower(name) = lower(:n)', ['n' => $name])->fetch() !== false) {
            $this->Flash->error(__('flash.group.name_exists'));
            $this->renderGroupList(true);

            return null;
        }
        $row = $conn->execute(
            'INSERT INTO "groups" (name, description) VALUES (:n, :d) RETURNING id',
            ['n' => $name, 'd' => $desc],
        )->fetch('assoc');
        (new AuditLogger())->log('group.create', 'group', (string)$row['id'], ['newValue' => ['name' => $name]]);
        $this->Flash->success(__('flash.group.created'));

        return $this->redirect(['action' => 'view', $row['id']]);
    }

    public function view(string $id): ?Response
    {
        if (!$this->isUuid($id)) {
            return $this->notFound();
        }
        $conn = ConnectionManager::get('default');
        $group = $conn->execute('SELECT * FROM "groups" WHERE id = :id', ['id' => $id])->fetch('assoc');
        if ($group === false) {
            $this->Flash->error(__('flash.group.not_found'));

            return $this->redirect(['action' => 'index']);
        }
        $members = $conn->execute(
            'SELECT u.id, u.username, u.email FROM users u JOIN groups_users gu ON gu.user_id = u.id '
            . 'WHERE gu.group_id = :id ORDER BY u.username',
            ['id' => $id],
        )->fetchAll('assoc');
        // `users` carries tenant_id but has no blocking RLS policy (pre-auth
        // exception), so this admin candidate list must filter by tenant itself —
        // otherwise it would offer users of OTHER tenants for membership.
        $candidates = $conn->execute(
            'SELECT id, username FROM users WHERE status <> \'anonymized\' '
            . 'AND tenant_id = core.current_tenant() '
            . 'AND id NOT IN (SELECT user_id FROM groups_users WHERE group_id = :id) ORDER BY username',
            ['id' => $id],
        )->fetchAll('assoc');
        // CLASS-level grants (resource_key NULL) keyed by resource: they prefill
        // the checkbox editor. Object-level rows are CLI territory (bin/cake
        // permission) and are deliberately not editable here.
        $grantRows = $conn->execute(
            'SELECT module_key, resource_type, can_browse, can_read, can_add, can_edit, can_delete, extra_actions '
            . 'FROM group_resource_permissions WHERE group_id = :id AND resource_key IS NULL',
            ['id' => $id],
        )->fetchAll('assoc');
        $grants = [];
        foreach ($grantRows as $g) {
            $grants[$g['module_key'] . '::' . $g['resource_type']] = $g;
        }
        // Object-level rows are CLI-managed and not editable here, but they must
        // stay VISIBLE (revision-proof norm): an auditing admin would otherwise
        // read "every box empty" as "no access" while object grants keep working.
        $objectCounts = [];
        foreach (
            $conn->execute(
                'SELECT module_key, resource_type, count(*) AS c FROM group_resource_permissions '
                . 'WHERE group_id = :id AND resource_key IS NOT NULL GROUP BY module_key, resource_type',
                ['id' => $id],
            )->fetchAll('assoc') as $o
        ) {
            $objectCounts[$o['module_key'] . '::' . $o['resource_type']] = (int)$o['c'];
        }
        // Only group-capable resources can be assigned in the group permission
        // editor (ch. 25.11), grouped by owning module for the accordion display.
        $resourceGroups = [];
        foreach ($this->groupCapableResources() as $r) {
            $mk = (string)$r['module_key'];
            $resourceGroups[$mk] ??= ['name' => (string)($r['module_name'] ?? '') ?: $mk, 'resources' => []];
            $resourceGroups[$mk]['resources'][] = $r;
        }
        $currentUserId = $this->currentUserId();
        $this->set(compact('group', 'members', 'candidates', 'grants', 'resourceGroups', 'objectCounts', 'currentUserId'));

        return null;
    }

    /**
     * All group-capable resources with their owning module's display name.
     *
     * @return list<array<string, mixed>>
     */
    private function groupCapableResources(): array
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return array_values($conn->execute(
            'SELECT r.module_key, r.resource_type, r.resource_name, r.is_scoped, r.extra_actions, '
            . 'm.name AS module_name FROM resources r '
            . 'LEFT JOIN modules m ON m.module_key = r.module_key '
            . 'WHERE r.group_capable = true ORDER BY m.name NULLS LAST, r.module_key, r.resource_name',
        )->fetchAll('assoc'));
    }

    public function setActive(string $id, string $flag): ?Response
    {
        $this->request->allowMethod('post');
        if (!$this->isUuid($id)) {
            return $this->notFound();
        }
        $active = $flag === 'on';
        // The protected administrator group must never be deactivated (locking every
        // admin out). Server-side guard, not just a hidden button.
        if (!$active && $this->isSystemGroup($id)) {
            $this->Flash->error(__('flash.group.system_protected'));

            return $this->redirect(['action' => 'view', $id]);
        }
        // PostgreSQL boolean: CakePHP's raw execute() binds PHP `false` as ''
        // (→ "invalid input syntax for type boolean"); hence explicit 'true'/'false'.
        ConnectionManager::get('default')->execute(
            'UPDATE "groups" SET active = :a, deactivated_at = CASE WHEN :a THEN NULL ELSE now() END WHERE id = :id',
            ['a' => $active ? 'true' : 'false', 'id' => $id],
        );
        (new AuditLogger())->log($active ? 'group.activate' : 'group.deactivate', 'group', $id, ['newValue' => ['active' => $active]]);
        $this->Flash->success(__('flash.group.status_updated'));

        return $this->redirect(['action' => 'view', $id]);
    }

    public function addMember(string $id): ?Response
    {
        $this->request->allowMethod('post');
        // Tenant isolation (both sides): the group AND the referenced user must belong
        // to the acting admin's tenant. `users` has NO RLS and the groups_users WITH
        // CHECK policy only validates the new row's OWN tenant_id — not the referenced
        // user's or group's — so without these explicit checks a POSTed foreign user_id
        // would be pulled into this tenant's group (inheriting its permissions), or the
        // admin's user could be slipped into a foreign group.
        if (!$this->isUuid($id) || !$this->groupInTenant($id)) {
            return $this->notFound();
        }
        $userId = (string)$this->request->getData('user_id');
        if (!$this->isUuid($userId) || !$this->userInTenant($userId)) {
            $this->Flash->error(__('flash.user.not_found'));

            return $this->redirect(['action' => 'view', $id]);
        }
        ConnectionManager::get('default')->execute(
            'INSERT INTO groups_users (group_id, user_id) VALUES (:g, :u) ON CONFLICT DO NOTHING',
            ['g' => $id, 'u' => $userId],
        );
        (new AuditLogger())->log('group.member_add', 'group', $id, ['newValue' => ['user' => $userId]]);
        $this->Flash->success(__('flash.group.member_added'));

        return $this->redirect(['action' => 'view', $id]);
    }

    public function removeMember(string $id, string $userId): ?Response
    {
        $this->request->allowMethod('post');
        if (!$this->isUuid($id, $userId)) {
            return $this->notFound();
        }
        // Self-lockout protection: an admin must not remove THEMSELVES from the
        // protected administrator (system) group — that would strip their own admin
        // rights (mirrors the user-area last-admin guards). Another admin can still
        // remove them; non-system groups are unaffected.
        if ($userId === $this->currentUserId() && $this->isSystemGroup($id)) {
            $this->Flash->error(__('flash.group.self_remove_admin'));

            return $this->redirect(['action' => 'view', $id]);
        }
        ConnectionManager::get('default')->execute(
            'DELETE FROM groups_users WHERE group_id = :g AND user_id = :u',
            ['g' => $id, 'u' => $userId],
        );
        (new AuditLogger())->log('group.member_remove', 'group', $id, ['oldValue' => ['user' => $userId]]);
        $this->Flash->success(__('flash.group.member_removed'));

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Saves the whole checkbox matrix in one go (ch. 25.4/25.7/25.11): every
     * REGISTERED group-capable resource is processed — checked boxes become the
     * class-wide grant, an entirely unchecked row revokes it. Iterating the
     * registry (not the posted keys) is fail-closed: a forged form key can
     * neither grant on an unregistered resource nor invent extra actions beyond
     * the ones the resource declares. Object-level rows (resource_key set, CLI-
     * managed) are untouched by design — the GUI edits object CLASSES only.
     */
    public function savePermissions(string $id): ?Response
    {
        $this->request->allowMethod('post');
        if (!$this->isUuid($id) || !$this->groupInTenant($id)) {
            return $this->notFound();
        }
        // The administrator group's rights are managed by group_init (full access);
        // editing them in the GUI could strip an admin's own access. Server-side guard.
        if ($this->isSystemGroup($id)) {
            $this->Flash->error(__('flash.group.system_protected_perms'));

            return $this->redirect(['action' => 'view', $id]);
        }
        $data = (array)$this->request->getData('perm');

        // Diff-aware save: the append-only audit_log must record CHANGES, not
        // every unchanged row of every save click — so revoke only what exists
        // and grant only what differs from the current class-level row.
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        $current = [];
        foreach (
            $conn->execute(
                'SELECT module_key, resource_type, can_browse, can_read, can_add, can_edit, can_delete, extra_actions, '
                . 'deny_browse, deny_read, deny_add, deny_edit, deny_delete, deny_extra '
                . 'FROM group_resource_permissions WHERE group_id = :id AND resource_key IS NULL',
                ['id' => $id],
            )->fetchAll('assoc') as $g
        ) {
            $current[$g['module_key'] . '::' . $g['resource_type']] = $g;
        }

        $service = new PermissionService();
        foreach ($this->groupCapableResources() as $r) {
            $moduleKey = (string)$r['module_key'];
            $resourceType = (string)$r['resource_type'];
            $rid = $moduleKey . '::' . $resourceType;
            $row = (array)($data[$rid] ?? []);

            $bread = [
                'browse' => !empty($row['browse']),
                'read' => !empty($row['read']),
                'edit' => !empty($row['edit']),
                'add' => !empty($row['add']),
                'delete' => !empty($row['delete']),
            ];
            // Extra actions (ch. 25.7): only the DECLARED ones are considered
            // from the FORM. Stored extras OUTSIDE the declaration (granted via
            // CLI, invisible in the checkbox editor) are carried over untouched
            // — a Save click must not silently strip another surface's state.
            $declared = is_string($r['extra_actions'] ?? null)
                ? (json_decode((string)$r['extra_actions'], true) ?: [])
                : (array)($r['extra_actions'] ?? []);
            $declaredNames = array_values(array_filter(array_map('strval', $declared)));
            $extra = [];
            foreach ($declaredNames as $name) {
                if (!empty($row['x'][$name])) {
                    $extra[$name] = true;
                }
            }
            $storedExtra = isset($current[$rid]) && is_string($current[$rid]['extra_actions'] ?? null)
                ? (json_decode((string)$current[$rid]['extra_actions'], true) ?: [])
                : [];
            foreach ($storedExtra as $name => $on) {
                if (!empty($on) && !in_array((string)$name, $declaredNames, true)) {
                    $extra[(string)$name] = true;
                }
            }

            if (!in_array(true, $bread, true) && $extra === []) {
                if (isset($current[$rid])) {
                    if ($this->hasDenyFlags($current[$rid])) {
                        // Deny-wins rows must SURVIVE an all-unchecked save:
                        // deleting the row would act as a grant (the deny
                        // disappears and another group's allow wins again).
                        // Clear only the allow side — grant() upserts the can_*/
                        // extra columns and leaves every deny_* column alone.
                        if ($this->grantDiffers($current[$rid], $bread, $extra)) {
                            $service->grant($id, $moduleKey, $resourceType, null, $bread, $extra);
                        }
                    } else {
                        $service->revoke($id, $moduleKey, $resourceType, null);
                    }
                }
            } elseif ($this->grantDiffers($current[$rid] ?? null, $bread, $extra)) {
                $service->grant($id, $moduleKey, $resourceType, null, $bread, $extra);
            }
        }
        $this->Flash->success(__('flash.group.perms_saved'));

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Whether the class-level row carries any deny rule (deny-wins, ch. 25):
     * such a row must never be deleted by the allow-side editor.
     *
     * @param array<string,mixed> $row
     */
    private function hasDenyFlags(array $row): bool
    {
        $truthy = static fn($v): bool => $v === true || $v === 't' || $v === '1' || $v === 1;
        foreach (['deny_browse', 'deny_read', 'deny_add', 'deny_edit', 'deny_delete'] as $col) {
            if ($truthy($row[$col] ?? false)) {
                return true;
            }
        }
        $denyExtra = is_string($row['deny_extra'] ?? null)
            ? (json_decode((string)$row['deny_extra'], true) ?: [])
            : (array)($row['deny_extra'] ?? []);

        return array_filter($denyExtra) !== [];
    }

    /**
     * Whether the desired checkbox state differs from the stored class-level row.
     *
     * @param array<string,mixed>|null $row
     * @param array<string,bool> $bread
     * @param array<string,bool> $extra
     */
    private function grantDiffers(?array $row, array $bread, array $extra): bool
    {
        if ($row === null) {
            return true;
        }
        $truthy = static fn($v): bool => $v === true || $v === 't' || $v === '1' || $v === 1;
        foreach (['browse', 'read', 'edit', 'add', 'delete'] as $a) {
            if ($bread[$a] !== $truthy($row['can_' . $a])) {
                return true;
            }
        }
        $stored = is_string($row['extra_actions'] ?? null)
            ? (json_decode((string)$row['extra_actions'], true) ?: [])
            : (array)($row['extra_actions'] ?? []);
        $storedOn = array_keys(array_filter($stored));
        sort($storedOn);
        $wanted = array_keys($extra);
        sort($wanted);

        return $storedOn !== $wanted;
    }

    /** Malformed ID (UUID guard): treat like an unknown group. */
    private function notFound(): ?Response
    {
        $this->Flash->error(__('flash.group.not_found'));

        return $this->redirect(['action' => 'index']);
    }

    /** The acting admin's own user id, or null when unauthenticated (fail-closed). */
    private function currentUserId(): ?string
    {
        $id = $this->identity()?->getIdentifier();

        return is_scalar($id) ? (string)$id : null;
    }

    /**
     * Whether the group is the protected administrator (system) group. RLS scopes the
     * read to the current tenant, so a foreign id yields no row (-> false).
     */
    private function isSystemGroup(string $id): bool
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        $row = $conn->execute('SELECT is_system FROM "groups" WHERE id = :id', ['id' => $id])->fetch('assoc');

        return $row !== false && filter_var($row['is_system'], FILTER_VALIDATE_BOOLEAN);
    }

    /** Whether $id is a group of the acting admin's OWN tenant (explicit, not via RLS). */
    private function groupInTenant(string $id): bool
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn->execute(
            'SELECT 1 FROM "groups" WHERE id = :id AND tenant_id = core.current_tenant()',
            ['id' => $id],
        )->fetch() !== false;
    }

    /** Whether $userId is a user of the acting admin's OWN tenant (users has no RLS). */
    private function userInTenant(string $userId): bool
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn->execute(
            'SELECT 1 FROM users WHERE id = :u AND tenant_id = core.current_tenant()',
            ['u' => $userId],
        )->fetch() !== false;
    }
}
