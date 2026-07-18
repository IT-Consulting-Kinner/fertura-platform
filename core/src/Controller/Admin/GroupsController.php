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
        $this->renderGroupList(false);
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
        $row = ConnectionManager::get('default')->execute(
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
        // Only group-capable resources can be assigned in the group permission
        // editor (ch. 25.11), grouped by owning module for the accordion display.
        $resourceGroups = [];
        foreach ($this->groupCapableResources() as $r) {
            $mk = (string)$r['module_key'];
            $resourceGroups[$mk] ??= ['name' => (string)($r['module_name'] ?? '') ?: $mk, 'resources' => []];
            $resourceGroups[$mk]['resources'][] = $r;
        }
        $this->set(compact('group', 'members', 'candidates', 'grants', 'resourceGroups'));

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
        $data = (array)$this->request->getData('perm');

        // Diff-aware save: the append-only audit_log must record CHANGES, not
        // every unchanged row of every save click — so revoke only what exists
        // and grant only what differs from the current class-level row.
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        $current = [];
        foreach (
            $conn->execute(
                'SELECT module_key, resource_type, can_browse, can_read, can_add, can_edit, can_delete, extra_actions '
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
            // Extra actions (ch. 25.7): only the DECLARED ones are considered.
            $declared = is_string($r['extra_actions'] ?? null)
                ? (json_decode((string)$r['extra_actions'], true) ?: [])
                : (array)($r['extra_actions'] ?? []);
            $extra = [];
            foreach ($declared as $name) {
                $name = (string)$name;
                if ($name !== '' && !empty($row['x'][$name])) {
                    $extra[$name] = true;
                }
            }

            if (!in_array(true, $bread, true) && $extra === []) {
                if (isset($current[$rid])) {
                    $service->revoke($id, $moduleKey, $resourceType, null);
                }
            } elseif ($this->grantDiffers($current[$rid] ?? null, $bread, $extra)) {
                $service->grant($id, $moduleKey, $resourceType, null, $bread, $extra);
            }
        }
        $this->Flash->success(__('flash.group.perms_saved'));

        return $this->redirect(['action' => 'view', $id]);
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
