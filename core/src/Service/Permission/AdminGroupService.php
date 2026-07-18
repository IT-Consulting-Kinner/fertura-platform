<?php
declare(strict_types=1);

namespace App\Service\Permission;

use App\Audit\AuditLogger;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * Ensures a tenant's ADMINISTRATOR group exists and holds full permissions on
 * every group-capable resource (ch. 25) — the bootstrap counterpart to the
 * group admin GUI, used by `bin/cake group_init` and `bin/cake create_admin`.
 *
 * Idempotent by design: re-running tops up the grants for resources registered
 * AFTER the group was created (e.g. a module installed later), without touching
 * memberships or unrelated permissions.
 */
class AdminGroupService
{
    public const DEFAULT_NAME = 'Administratoren';

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
     * Creates the admin group in `$tenantId` when missing (matched
     * case-insensitively by name) and grants it full BREAD + every extra action
     * on ALL group-capable resources (class-wide, resource_key NULL).
     *
     * NOTE for CLI callers: the RLS tenant context must be set to `$tenantId`
     * beforehand (`set_config('app.current_tenant_id', …)`) — the permission
     * rows inherit their tenant from the context.
     *
     * @return array{id: string, created: bool, granted: int}
     */
    public function ensure(string $tenantId, string $name = self::DEFAULT_NAME): array
    {
        $conn = $this->conn();
        $row = $conn->execute(
            'SELECT id FROM "groups" WHERE lower(name) = lower(:n) AND tenant_id = :t',
            ['n' => $name, 't' => $tenantId],
        )->fetch('assoc');

        $created = false;
        if ($row === false) {
            $row = $conn->execute(
                'INSERT INTO "groups" (name, description, tenant_id) VALUES (:n, :d, :t) RETURNING id',
                ['n' => $name, 'd' => 'Vollzugriff auf alle Modul-Ressourcen (group_init).', 't' => $tenantId],
            )->fetch('assoc');
            $created = true;
            $this->audit->log('group.create', 'group', (string)$row['id'], [
                'newValue' => ['name' => $name, 'by' => 'group_init'],
            ]);
        }
        $groupId = (string)$row['id'];

        // Top-up grants: every group-capable resource gets full BREAD plus all
        // of its declared extra actions. grant() upserts, so re-runs are cheap
        // and pick up newly installed modules.
        $resources = $conn->execute(
            'SELECT module_key, resource_type, extra_actions FROM resources WHERE group_capable = true',
        )->fetchAll('assoc');
        $permissions = new PermissionService($this->audit);
        $full = ['browse' => true, 'read' => true, 'add' => true, 'edit' => true, 'delete' => true];
        foreach ($resources as $r) {
            $extraNames = is_string($r['extra_actions'] ?? null)
                ? (json_decode((string)$r['extra_actions'], true) ?: [])
                : (array)($r['extra_actions'] ?? []);
            $extra = [];
            foreach ($extraNames as $ea) {
                if (is_string($ea) && $ea !== '') {
                    $extra[$ea] = true;
                }
            }
            $permissions->grant($groupId, (string)$r['module_key'], (string)$r['resource_type'], null, $full, $extra);
        }

        return ['id' => $groupId, 'created' => $created, 'granted' => count($resources)];
    }

    /** Adds a user to the group (idempotent). */
    public function addUser(string $groupId, string $userId): void
    {
        $this->conn()->execute(
            'INSERT INTO groups_users (group_id, user_id) VALUES (:g, :u) ON CONFLICT DO NOTHING',
            ['g' => $groupId, 'u' => $userId],
        );
        $this->audit->log('group.member_add', 'group', $groupId, [
            'newValue' => ['user' => $userId, 'by' => 'group_init'],
        ]);
    }
}
