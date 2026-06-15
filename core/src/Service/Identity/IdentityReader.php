<?php
declare(strict_types=1);

namespace App\Service\Identity;

use App\Infrastructure\Uuid;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * Minimal, read-only access to platform users and groups for **modules**
 * (capability #5b). A module (e.g. Ticketing) needs to resolve users/groups for
 * its own mappings — queue ↔ group assignment, ticket owner display — but must
 * NOT reach into the Core identity tables directly (boundary). This Core service
 * is the sanctioned read surface, used the same way as other Core services a
 * module may call ({@see \App\Service\Mail\MailService}).
 *
 * **Decision D4 = minimal (data-frugal/GDPR):** only ids, a display name and
 * group ids/names are exposed — **no email, status or other PII**. A module that
 * genuinely needs more must request a dedicated, audited capability.
 *
 * Reads run in the caller's RLS context (no privileged bypass). Group reads are
 * tenant-scoped by the RLS policies on `groups`/`groups_users` (E170); the user
 * reads filter by tenant explicitly (`tenant_id = core.current_tenant()`) because
 * `users` is a pre-auth table with no blocking policy, so without the filter a
 * module's user dropdown would surface foreign-tenant users (E173).
 */
class IdentityReader
{
    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /**
     * Active users (id + display name).
     *
     * @return list<array{id:string, display_name:string}>
     */
    public function users(): array
    {
        $rows = $this->conn()->execute(
            'SELECT id, username, first_name, last_name FROM users '
            . "WHERE status = 'active' AND tenant_id = core.current_tenant() ORDER BY username",
        )->fetchAll('assoc');

        return array_values(array_map(fn(array $r): array => [
            'id' => (string)$r['id'],
            'display_name' => $this->displayName($r),
        ], $rows));
    }

    /**
     * A single active user (id + display name), or null.
     *
     * @return array{id:string, display_name:string}|null
     */
    public function resolveUser(string $userId): ?array
    {
        if (!Uuid::isValid($userId)) {
            return null;
        }
        $row = $this->conn()->execute(
            'SELECT id, username, first_name, last_name FROM users '
            . "WHERE id = :u AND status = 'active' AND tenant_id = core.current_tenant()",
            ['u' => $userId],
        )->fetch('assoc');

        return $row === false ? null : ['id' => (string)$row['id'], 'display_name' => $this->displayName($row)];
    }

    /**
     * All groups (id + name).
     *
     * @return list<array{id:string, name:string}>
     */
    public function groups(): array
    {
        $rows = $this->conn()->execute('SELECT id, name FROM "groups" ORDER BY name')->fetchAll('assoc');

        return array_values(array_map(
            static fn(array $r): array => ['id' => (string)$r['id'], 'name' => (string)$r['name']],
            $rows,
        ));
    }

    /**
     * Groups a user belongs to (id + name).
     *
     * @return list<array{id:string, name:string}>
     */
    public function userGroups(string $userId): array
    {
        if (!Uuid::isValid($userId)) {
            return [];
        }
        $rows = $this->conn()->execute(
            'SELECT g.id, g.name FROM "groups" g JOIN groups_users gu ON gu.group_id = g.id '
            . 'WHERE gu.user_id = :u ORDER BY g.name',
            ['u' => $userId],
        )->fetchAll('assoc');

        return array_values(array_map(
            static fn(array $r): array => ['id' => (string)$r['id'], 'name' => (string)$r['name']],
            $rows,
        ));
    }

    /** @param array<string,mixed> $r */
    private function displayName(array $r): string
    {
        $name = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));

        return $name !== '' ? $name : (string)$r['username'];
    }
}
