<?php
declare(strict_types=1);

namespace App\Service\Admin;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * Loads and stores a user's per-list view preferences (Paket 2): the page size
 * and filter values last chosen for a given admin list, keyed by
 * (user_id, list_key). The payload is an opaque JSONB bag; this service does not
 * interpret the filters — the controller owns which keys are valid.
 *
 * Tenant isolation is enforced by RLS on `core.user_list_prefs` AND by the
 * user_id predicate (a session only ever acts as its own user), so a stored
 * preference never crosses a tenant or a user boundary.
 */
class ListPrefsService
{
    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /**
     * The stored preferences for a list, or an empty bag when none exist.
     *
     * @return array{per_page: int|null, filters: array<string, string>}
     */
    public function load(string $userId, string $listKey): array
    {
        $row = $this->conn()->execute(
            'SELECT prefs FROM user_list_prefs WHERE user_id = :u AND list_key = :k',
            ['u' => $userId, 'k' => $listKey],
        )->fetch('assoc');

        $prefs = $row !== false && is_string($row['prefs'] ?? null)
            ? (json_decode((string)$row['prefs'], true) ?: [])
            : [];
        $perPage = isset($prefs['per_page']) && is_int($prefs['per_page']) ? $prefs['per_page'] : null;
        $filters = [];
        foreach ((array)($prefs['filters'] ?? []) as $k => $v) {
            if (is_scalar($v)) {
                $filters[(string)$k] = (string)$v;
            }
        }

        return ['per_page' => $perPage, 'filters' => $filters];
    }

    /**
     * Upserts the preferences for a list. Empty filter values are dropped so the
     * bag stays small; `null` per_page stores no page-size override.
     *
     * @param array<string, string> $filters
     */
    public function save(string $userId, string $listKey, ?int $perPage, array $filters): void
    {
        $clean = [];
        foreach ($filters as $k => $v) {
            if ($v !== '') {
                $clean[$k] = $v;
            }
        }
        $prefs = ['filters' => $clean];
        if ($perPage !== null) {
            $prefs['per_page'] = $perPage;
        }

        // The conflict target matches the tenant-scoped unique
        // (tenant_id, user_id, list_key); tenant_id is the row's DEFAULT
        // core.current_tenant(), evaluated before the conflict check.
        $this->conn()->execute(
            'INSERT INTO user_list_prefs (user_id, list_key, prefs) VALUES (:u, :k, CAST(:p AS jsonb)) '
            . 'ON CONFLICT (tenant_id, user_id, list_key) DO UPDATE SET prefs = EXCLUDED.prefs, updated_at = now()',
            ['u' => $userId, 'k' => $listKey, 'p' => json_encode($prefs)],
        );
    }
}
