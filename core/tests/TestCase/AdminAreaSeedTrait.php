<?php
declare(strict_types=1);

namespace App\Test\TestCase;

use Cake\Datasource\ConnectionManager;

/**
 * Test helper for the per-GROUP admin-area model ({@see \CoreGroupAdminAreas}):
 * grants a user a set of administration areas the way production does — through
 * membership in a group that holds those areas — replacing the old per-user
 * `user_admin_areas` inserts.
 *
 * Each call creates a DEDICATED group (uniquely named `zzseedarea_*`, so it never
 * collides with or pollutes the prefix-filtered group enumerations in other tests)
 * carrying EXACTLY the requested areas, and adds the user to it. This preserves the
 * old grant's exact semantics (a user granted only `core_config` is still denied
 * everything else), so it is a safe drop-in for both access and denial tests.
 *
 * tenant_id is set EXPLICITLY on every row: the test connection is the BYPASSRLS
 * superuser and setUp runs outside a request, so `core.current_tenant()` is NULL —
 * relying on the column default would write NULL-tenant rows the request-path
 * resolver (which filters on the group's tenant_id) could not see.
 */
trait AdminAreaSeedTrait
{
    /**
     * Grants $userId the given admin area(s) via a fresh dedicated group in the
     * user's tenant. Returns the group id (for the rare test that manipulates it).
     */
    protected function grantAdminAreas(string $userId, string ...$areaKeys): string
    {
        $conn = ConnectionManager::get('default');
        $tenantId = (string)$conn->execute(
            'SELECT tenant_id FROM users WHERE id = :u',
            ['u' => $userId],
        )->fetch('assoc')['tenant_id'];
        $groupId = (string)$conn->execute(
            'INSERT INTO "groups" (name, tenant_id) VALUES (:n, :t) RETURNING id',
            ['n' => 'zzseedarea_' . bin2hex(random_bytes(6)), 't' => $tenantId],
        )->fetch('assoc')['id'];
        foreach ($areaKeys as $areaKey) {
            $conn->execute(
                'INSERT INTO group_admin_areas (group_id, admin_area_key, tenant_id) '
                . 'VALUES (:g, :a, :t) ON CONFLICT DO NOTHING',
                ['g' => $groupId, 'a' => $areaKey, 't' => $tenantId],
            );
        }
        $conn->execute(
            'INSERT INTO groups_users (group_id, user_id, tenant_id) VALUES (:g, :u, :t) ON CONFLICT DO NOTHING',
            ['g' => $groupId, 'u' => $userId, 't' => $tenantId],
        );

        return $groupId;
    }

    /**
     * Makes $userId a FULL admin: a member of an active `is_system` group in their
     * tenant (which holds ALL areas by the wildcard rule). Reuses the tenant's
     * existing system group when present. Use only when the test needs "an admin"
     * rather than a specific area set.
     */
    protected function grantAllAdminAreas(string $userId): string
    {
        $conn = ConnectionManager::get('default');
        $tenantId = (string)$conn->execute(
            'SELECT tenant_id FROM users WHERE id = :u',
            ['u' => $userId],
        )->fetch('assoc')['tenant_id'];
        $row = $conn->execute(
            'SELECT id FROM "groups" WHERE is_system AND active AND tenant_id = :t LIMIT 1',
            ['t' => $tenantId],
        )->fetch('assoc');
        if ($row === false) {
            $row = $conn->execute(
                'INSERT INTO "groups" (name, tenant_id, is_system) VALUES (:n, :t, true) RETURNING id',
                ['n' => 'zzseedadmin_' . bin2hex(random_bytes(6)), 't' => $tenantId],
            )->fetch('assoc');
        }
        $groupId = (string)$row['id'];
        $conn->execute(
            'INSERT INTO groups_users (group_id, user_id, tenant_id) VALUES (:g, :u, :t) ON CONFLICT DO NOTHING',
            ['g' => $groupId, 'u' => $userId, 't' => $tenantId],
        );

        return $groupId;
    }
}
