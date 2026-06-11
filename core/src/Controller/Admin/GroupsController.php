<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditLogger;
use App\Service\Permission\PermissionService;
use Cake\Datasource\ConnectionManager;

/**
 * Group management including membership and BREAD resource permissions
 * (administration area "User and Group Management").
 */
class GroupsController extends AdminController
{
    protected ?string $requiredArea = 'user_group_admin';

    public function index(): void
    {
        $groups = ConnectionManager::get('default')->execute(
            'SELECT g.id, g.name, g.description, g.active, '
            . '(SELECT count(*) FROM groups_users gu WHERE gu.group_id = g.id) AS member_count '
            . 'FROM "groups" g ORDER BY g.name',
        )->fetchAll('assoc');
        $this->set(compact('groups'));
    }

    public function add()
    {
        if ($this->request->is('post')) {
            $name = trim((string)$this->request->getData('name'));
            $desc = trim((string)$this->request->getData('description')) ?: null;
            if ($name === '') {
                $this->Flash->error(__('flash.group.name_empty'));
            } else {
                $row = ConnectionManager::get('default')->execute(
                    'INSERT INTO "groups" (name, description) VALUES (:n, :d) RETURNING id',
                    ['n' => $name, 'd' => $desc],
                )->fetch('assoc');
                (new AuditLogger())->log('group.create', 'group', (string)$row['id'], ['newValue' => ['name' => $name]]);
                $this->Flash->success(__('flash.group.created'));

                return $this->redirect(['action' => 'view', $row['id']]);
            }
        }

        return null;
    }

    public function view(string $id)
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
        $candidates = $conn->execute(
            'SELECT id, username FROM users WHERE status <> \'anonymized\' '
            . 'AND id NOT IN (SELECT user_id FROM groups_users WHERE group_id = :id) ORDER BY username',
            ['id' => $id],
        )->fetchAll('assoc');
        $permissions = $conn->execute(
            'SELECT module_key, resource_type, resource_key, can_browse, can_read, can_add, can_edit, can_delete, extra_actions '
            . 'FROM group_resource_permissions WHERE group_id = :id ORDER BY module_key, resource_type, coalesce(resource_key, \'*\')',
            ['id' => $id],
        )->fetchAll('assoc');
        // Only group-capable resources can be assigned in the group permission editor (ch. 25.11).
        $resources = $conn->execute(
            'SELECT module_key, resource_type, resource_name, is_scoped, extra_actions '
            . 'FROM resources WHERE group_capable = true ORDER BY module_key, resource_name',
        )->fetchAll('assoc');
        $this->set(compact('group', 'members', 'candidates', 'permissions', 'resources'));

        return null;
    }

    public function setActive(string $id, string $flag)
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

    public function addMember(string $id)
    {
        $this->request->allowMethod('post');
        if (!$this->isUuid($id)) {
            return $this->notFound();
        }
        $userId = (string)$this->request->getData('user_id');
        if ($this->isUuid($userId)) {
            ConnectionManager::get('default')->execute(
                'INSERT INTO groups_users (group_id, user_id) VALUES (:g, :u) ON CONFLICT DO NOTHING',
                ['g' => $id, 'u' => $userId],
            );
            (new AuditLogger())->log('group.member_add', 'group', $id, ['newValue' => ['user' => $userId]]);
            $this->Flash->success(__('flash.group.member_added'));
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    public function removeMember(string $id, string $userId)
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

    public function setPermission(string $id)
    {
        $this->request->allowMethod('post');
        if (!$this->isUuid($id)) {
            return $this->notFound();
        }
        $data = $this->request->getData();
        [$moduleKey, $resourceType] = array_pad(explode('::', (string)($data['resource'] ?? ''), 2), 2, '');
        if ($moduleKey === '' || $resourceType === '') {
            $this->Flash->error(__('flash.group.invalid_resource'));

            return $this->redirect(['action' => 'view', $id]);
        }
        // Object class (empty) or a concrete single object (ch. 25.4 / 25.11).
        $resourceKey = trim((string)($data['resource_key'] ?? '')) ?: null;

        $bread = [
            'browse' => !empty($data['can_browse']),
            'read' => !empty($data['can_read']),
            'add' => !empty($data['can_add']),
            'edit' => !empty($data['can_edit']),
            'delete' => !empty($data['can_delete']),
        ];
        // Extra actions (ch. 25.7): only apply those marked as true.
        $extra = [];
        foreach ((array)($data['extra'] ?? []) as $name => $on) {
            if (!empty($on)) {
                $extra[(string)$name] = true;
            }
        }

        $service = new PermissionService();
        if (!in_array(true, $bread, true) && $extra === []) {
            $service->revoke($id, $moduleKey, $resourceType, $resourceKey);
            $this->Flash->success(__('flash.group.perms_revoked'));
        } else {
            $service->grant($id, $moduleKey, $resourceType, $resourceKey, $bread, $extra);
            $this->Flash->success(__('flash.group.perms_set'));
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /** Malformed ID (UUID guard): treat like an unknown group. */
    private function notFound(): ?\Cake\Http\Response
    {
        $this->Flash->error(__('flash.group.not_found'));

        return $this->redirect(['action' => 'index']);
    }
}
