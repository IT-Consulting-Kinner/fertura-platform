<?php
declare(strict_types=1);

namespace App\Service\Permission;

use App\Audit\AuditLogger;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * BREAD permission check (ch. 25 / 27.16). Additive aggregation across the active
 * groups of an active user **plus explicit deny rules**: a deny on any of the
 * groups overrides every allow (deny-wins). Always server-side; identical for
 * GUI/API/CLI.
 */
class PermissionService
{
    private const BREAD = [
        'browse' => 'can_browse',
        'read' => 'can_read',
        'add' => 'can_add',
        'edit' => 'can_edit',
        'delete' => 'can_delete',
    ];

    private const DENY = [
        'browse' => 'deny_browse',
        'read' => 'deny_read',
        'add' => 'deny_add',
        'edit' => 'deny_edit',
        'delete' => 'deny_delete',
    ];

    private AuditLogger $audit;

    public function __construct(?AuditLogger $audit = null)
    {
        $this->audit = $audit ?? new AuditLogger();
    }

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /**
     * Active group IDs of an ACTIVE user (for aggregation + RLS context).
     *
     * @return list<string>
     */
    public function activeGroupIds(string $userId): array
    {
        $user = $this->conn()->execute(
            'SELECT status FROM users WHERE id = :id',
            ['id' => $userId],
        )->fetch('assoc');
        if ($user === false || $user['status'] !== 'active') {
            return [];
        }

        $rows = $this->conn()->execute(
            'SELECT g.id FROM "groups" g JOIN groups_users gu ON gu.group_id = g.id '
            . 'WHERE gu.user_id = :id AND g.active',
            ['id' => $userId],
        )->fetchAll('assoc');

        return array_map(static fn($r) => (string)$r['id'], $rows);
    }

    /**
     * May the user perform the BREAD action or extra action on the resource?
     * resourceKey = null checks the object class; if set, checks class AND object.
     */
    public function canPerform(
        string $userId,
        string $moduleKey,
        string $resourceType,
        ?string $resourceKey,
        string $action,
    ): bool {
        $groups = $this->activeGroupIds($userId);
        if ($groups === []) {
            return false;
        }

        $placeholders = [];
        $params = ['m' => $moduleKey, 't' => $resourceType, 'k' => $resourceKey];
        foreach ($groups as $i => $gid) {
            $placeholders[] = ":g$i";
            $params["g$i"] = $gid;
        }

        $rows = $this->conn()->execute(
            'SELECT can_browse, can_read, can_add, can_edit, can_delete, extra_actions, '
            . 'deny_browse, deny_read, deny_add, deny_edit, deny_delete, deny_extra '
            . 'FROM group_resource_permissions WHERE group_id IN (' . implode(',', $placeholders) . ') '
            . 'AND module_key = :m AND resource_type = :t AND (resource_key IS NULL OR resource_key = :k)',
            $params,
        )->fetchAll('assoc');

        $action = strtolower($action);
        $allowed = false;
        foreach ($rows as $row) {
            if (isset(self::BREAD[$action])) {
                // Deny-wins: an explicit deny overrides every allow.
                if ($this->truthy($row[self::DENY[$action]] ?? false)) {
                    return false;
                }
                if ($this->truthy($row[self::BREAD[$action]])) {
                    $allowed = true;
                }
            } else {
                $deny = $row['deny_extra'] ? json_decode((string)$row['deny_extra'], true) : [];
                if (is_array($deny) && !empty($deny[$action])) {
                    return false;
                }
                $extra = $row['extra_actions'] ? json_decode((string)$row['extra_actions'], true) : [];
                if (is_array($extra) && !empty($extra[$action])) {
                    $allowed = true;
                }
            }
        }

        return $allowed;
    }

    /**
     * Effective permissions (union) of a user on a resource.
     *
     * @return array{bread: array<string,bool>, extra_actions: array<string,bool>}
     */
    public function getEffectivePermissions(
        string $userId,
        string $moduleKey,
        string $resourceType,
        ?string $resourceKey = null,
    ): array {
        $bread = ['browse' => false, 'read' => false, 'add' => false, 'edit' => false, 'delete' => false];
        $extra = [];
        foreach (array_keys($bread) as $a) {
            $bread[$a] = $this->canPerform($userId, $moduleKey, $resourceType, $resourceKey, $a);
        }

        return ['bread' => $bread, 'extra_actions' => $extra];
    }

    /**
     * Grants/updates a group's permissions on a resource (additive).
     *
     * @param array<string,bool> $bread
     * @param array<string,bool> $extraActions
     */
    public function grant(
        string $groupId,
        string $moduleKey,
        string $resourceType,
        ?string $resourceKey,
        array $bread,
        array $extraActions = [],
    ): void {
        $this->conn()->execute(
            'INSERT INTO group_resource_permissions '
            . '(group_id, module_key, resource_type, resource_key, can_browse, can_read, can_add, can_edit, can_delete, extra_actions) '
            . 'VALUES (:g, :m, :t, :k, :b, :r, :a, :e, :d, CAST(:x AS jsonb)) '
            . "ON CONFLICT (group_id, module_key, resource_type, coalesce(resource_key, '*')) DO UPDATE SET "
            . 'can_browse = EXCLUDED.can_browse, can_read = EXCLUDED.can_read, can_add = EXCLUDED.can_add, '
            . 'can_edit = EXCLUDED.can_edit, can_delete = EXCLUDED.can_delete, extra_actions = EXCLUDED.extra_actions',
            [
                'g' => $groupId, 'm' => $moduleKey, 't' => $resourceType, 'k' => $resourceKey,
                'b' => !empty($bread['browse']) ? 'true' : 'false',
                'r' => !empty($bread['read']) ? 'true' : 'false',
                'a' => !empty($bread['add']) ? 'true' : 'false',
                'e' => !empty($bread['edit']) ? 'true' : 'false',
                'd' => !empty($bread['delete']) ? 'true' : 'false',
                'x' => $extraActions === [] ? null : json_encode($extraActions),
            ],
        );
        $this->audit->log('bread_rights.update', 'resource', "$moduleKey.$resourceType", [
            'newValue' => ['group' => $groupId, 'bread' => $bread, 'extra' => $extraActions, 'key' => $resourceKey],
            'moduleKey' => $moduleKey,
        ]);
    }

    /**
     * Sets a group's **deny rules** on a resource (deny-wins). Updates only the
     * deny columns — existing allow permissions on the same row are preserved.
     *
     * @param array<string,bool> $bread deny per BREAD action
     * @param array<string,bool> $extraActions deny per extra action
     */
    public function deny(
        string $groupId,
        string $moduleKey,
        string $resourceType,
        ?string $resourceKey,
        array $bread,
        array $extraActions = [],
    ): void {
        $this->conn()->execute(
            'INSERT INTO group_resource_permissions '
            . '(group_id, module_key, resource_type, resource_key, deny_browse, deny_read, deny_add, deny_edit, deny_delete, deny_extra) '
            . 'VALUES (:g, :m, :t, :k, :b, :r, :a, :e, :d, CAST(:x AS jsonb)) '
            . "ON CONFLICT (group_id, module_key, resource_type, coalesce(resource_key, '*')) DO UPDATE SET "
            . 'deny_browse = EXCLUDED.deny_browse, deny_read = EXCLUDED.deny_read, deny_add = EXCLUDED.deny_add, '
            . 'deny_edit = EXCLUDED.deny_edit, deny_delete = EXCLUDED.deny_delete, deny_extra = EXCLUDED.deny_extra',
            [
                'g' => $groupId, 'm' => $moduleKey, 't' => $resourceType, 'k' => $resourceKey,
                'b' => !empty($bread['browse']) ? 'true' : 'false',
                'r' => !empty($bread['read']) ? 'true' : 'false',
                'a' => !empty($bread['add']) ? 'true' : 'false',
                'e' => !empty($bread['edit']) ? 'true' : 'false',
                'd' => !empty($bread['delete']) ? 'true' : 'false',
                'x' => $extraActions === [] ? null : json_encode($extraActions),
            ],
        );
        $this->audit->log('bread_rights.deny', 'resource', "$moduleKey.$resourceType", [
            'newValue' => ['group' => $groupId, 'deny' => $bread, 'deny_extra' => $extraActions, 'key' => $resourceKey],
            'moduleKey' => $moduleKey,
        ]);
    }

    public function revoke(string $groupId, string $moduleKey, string $resourceType, ?string $resourceKey): void
    {
        $this->conn()->execute(
            'DELETE FROM group_resource_permissions WHERE group_id = :g AND module_key = :m '
            . "AND resource_type = :t AND coalesce(resource_key, '*') = coalesce(:k, '*')",
            ['g' => $groupId, 'm' => $moduleKey, 't' => $resourceType, 'k' => $resourceKey],
        );
        $this->audit->log('group_resource_mapping.remove', 'resource', "$moduleKey.$resourceType", [
            'oldValue' => ['group' => $groupId, 'key' => $resourceKey],
            'moduleKey' => $moduleKey,
        ]);
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }
}
