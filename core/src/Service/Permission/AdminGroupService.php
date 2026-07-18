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
 * memberships or unrelated permissions. Grants that already carry the full set
 * are skipped, so re-runs do not flood the append-only audit_log.
 *
 * DOCUMENTED name-based idempotency: the group is matched by (tenant, name,
 * case-insensitive). If a tenant admin created a DIFFERENT group under this
 * name beforehand, ensure() escalates exactly that group to full rights — the
 * name is therefore reserved for the administrator role by convention (command
 * help says so). Re-runs also restore deliberately narrowed ALLOW flags back to
 * full (deny rules are untouched and keep winning): "top-up" means full rights.
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
     * on ALL group-capable resources (class-wide, resource_key NULL). Runs in
     * ONE transaction (ch. 1.8: no partially-granted group on failure).
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

        /** @var array{id: string, created: bool, granted: int} $result */
        $result = $conn->transactional(function (Connection $conn) use ($tenantId, $name): array {
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

            // Top-up grants: every group-capable resource gets full BREAD plus
            // all of its declared extra actions. Rows already carrying the full
            // set are SKIPPED (idempotent re-runs stay silent in the audit log);
            // grant() upserts, so newly installed modules are picked up.
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
                $moduleKey = (string)$r['module_key'];
                $resourceType = (string)$r['resource_type'];
                if ($this->hasFullGrant($conn, $groupId, $moduleKey, $resourceType, array_keys($extra))) {
                    continue;
                }
                $permissions->grant($groupId, $moduleKey, $resourceType, null, $full, $extra);
            }

            return ['id' => $groupId, 'created' => $created, 'granted' => count($resources)];
        });

        return $result;
    }

    /** Adds a user to the group (idempotent). `$by` names the acting command for the audit trail. */
    public function addUser(string $groupId, string $userId, string $by = 'group_init'): void
    {
        $inserted = $this->conn()->execute(
            'INSERT INTO groups_users (group_id, user_id) VALUES (:g, :u) ON CONFLICT DO NOTHING',
            ['g' => $groupId, 'u' => $userId],
        )->rowCount();
        // Only a NEW membership is audit-worthy (re-runs stay silent).
        if ($inserted > 0) {
            $this->audit->log('group.member_add', 'group', $groupId, [
                'newValue' => ['user' => $userId, 'by' => $by],
            ]);
        }
    }

    /**
     * Whether the class-wide grant row already carries full BREAD and all the
     * declared extra actions — then the upsert (and its audit entry) is skipped.
     *
     * @param list<string> $extraNames
     */
    private function hasFullGrant(
        Connection $conn,
        string $groupId,
        string $moduleKey,
        string $resourceType,
        array $extraNames,
    ): bool {
        $row = $conn->execute(
            'SELECT can_browse, can_read, can_add, can_edit, can_delete, extra_actions '
            . 'FROM group_resource_permissions WHERE group_id = :g AND module_key = :m '
            . 'AND resource_type = :t AND resource_key IS NULL',
            ['g' => $groupId, 'm' => $moduleKey, 't' => $resourceType],
        )->fetch('assoc');
        if ($row === false) {
            return false;
        }
        foreach (['can_browse', 'can_read', 'can_add', 'can_edit', 'can_delete'] as $col) {
            if (!in_array($row[$col], [true, 't', '1', 1], true)) {
                return false;
            }
        }
        $extra = is_string($row['extra_actions'] ?? null)
            ? (json_decode((string)$row['extra_actions'], true) ?: [])
            : (array)($row['extra_actions'] ?? []);
        foreach ($extraNames as $name) {
            if (empty($extra[$name])) {
                return false;
            }
        }

        return true;
    }
}
