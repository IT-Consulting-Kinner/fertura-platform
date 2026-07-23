<?php
declare(strict_types=1);

namespace App\Service\Permission;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * Resolves the ADMINISTRATION AREAS a user effectively holds — the single source
 * of truth for the admin-area gate after grants moved from per-user to per-GROUP
 * ({@see \CoreGroupAdminAreas}).
 *
 * Effective areas = the UNION of `group_admin_areas` over the user's ACTIVE groups,
 * with one wildcard: a member of an active `is_system` group (the "Administrators"
 * group) holds EVERY area in the catalog — resolved virtually at check time, so a
 * newly module-registered area is covered without any backfill and never drifts.
 *
 * All queries carry an EXPLICIT `tenant_id = core.current_tenant()` predicate on
 * `groups` IN ADDITION to RLS (dual-role convention): in production the NOBYPASSRLS
 * app role is scoped by RLS, but the privileged/test BYPASSRLS role is not — the
 * explicit predicate keeps a user's admin areas confined to their OWN tenant on
 * every connection. It therefore MUST run in the request path (tenant context set);
 * a bare call outside a request sees `core.current_tenant()` = NULL and resolves to
 * nothing (fail-closed), never a cross-tenant leak.
 */
class AdminAreaResolver
{
    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /**
     * The admin-area keys $userId effectively holds in the current tenant.
     *
     * @return list<string>
     */
    public function areasFor(string $userId): array
    {
        $conn = $this->conn();
        if ($this->isAdminGroupMember($userId)) {
            // Wildcard: the Administrators group always holds every catalog area.
            $rows = $conn->execute('SELECT area_key FROM admin_areas')->fetchAll('assoc');

            return array_values(array_map(static fn($r) => (string)$r['area_key'], $rows));
        }
        $rows = $conn->execute(
            'SELECT DISTINCT gaa.admin_area_key AS k '
            . 'FROM group_admin_areas gaa '
            . 'JOIN groups_users gu ON gu.group_id = gaa.group_id '
            . 'JOIN "groups" g ON g.id = gaa.group_id '
            . 'WHERE gu.user_id = :u AND g.active AND g.tenant_id = core.current_tenant()',
            ['u' => $userId],
        )->fetchAll('assoc');

        return array_values(array_map(static fn($r) => (string)$r['k'], $rows));
    }

    /**
     * Whether $userId holds ANY admin area in the current tenant — the gate for
     * "is an admin at all" ({@see \App\Controller\AppController::isOperatorAdmin}).
     */
    public function hasAny(string $userId): bool
    {
        $row = $this->conn()->execute(
            'SELECT EXISTS (SELECT 1 FROM groups_users gu '
            . 'JOIN "groups" g ON g.id = gu.group_id '
            . 'WHERE gu.user_id = :u AND g.active AND g.tenant_id = core.current_tenant() '
            . 'AND (g.is_system OR EXISTS (SELECT 1 FROM group_admin_areas gaa WHERE gaa.group_id = g.id))) AS ok',
            ['u' => $userId],
        )->fetch('assoc');

        return $row !== false && ($row['ok'] === true || $row['ok'] === 't');
    }

    /**
     * How many ACTIVE users of the current tenant effectively hold $areaKey,
     * optionally excluding one user — the basis for the last-admin self-lockout
     * guard. A member of an `is_system` group counts for EVERY area (wildcard).
     */
    public function activeHoldersOf(string $areaKey, ?string $excludeUserId = null): int
    {
        $sql = 'SELECT count(DISTINCT gu.user_id) AS c '
            . 'FROM groups_users gu '
            . 'JOIN "groups" g ON g.id = gu.group_id '
            . 'JOIN users u ON u.id = gu.user_id '
            . 'WHERE g.active AND g.tenant_id = core.current_tenant() '
            . "AND u.status = 'active' AND u.tenant_id = core.current_tenant() "
            . 'AND (g.is_system OR EXISTS (SELECT 1 FROM group_admin_areas gaa '
            . 'WHERE gaa.group_id = g.id AND gaa.admin_area_key = :area))';
        $params = ['area' => $areaKey];
        if ($excludeUserId !== null) {
            $sql .= ' AND gu.user_id <> :ex';
            $params['ex'] = $excludeUserId;
        }

        return (int)$this->conn()->execute($sql, $params)->fetch('assoc')['c'];
    }

    /** Whether $userId is a member of an active `is_system` group in the current tenant. */
    private function isAdminGroupMember(string $userId): bool
    {
        $row = $this->conn()->execute(
            'SELECT EXISTS (SELECT 1 FROM groups_users gu '
            . 'JOIN "groups" g ON g.id = gu.group_id '
            . 'WHERE gu.user_id = :u AND g.active AND g.is_system '
            . 'AND g.tenant_id = core.current_tenant()) AS ok',
            ['u' => $userId],
        )->fetch('assoc');

        return $row !== false && ($row['ok'] === true || $row['ok'] === 't');
    }
}
