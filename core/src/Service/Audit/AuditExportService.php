<?php
declare(strict_types=1);

namespace App\Service\Audit;

use App\Infrastructure\Db;
use App\Infrastructure\Uuid;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Generator;

/**
 * Time-ranged / filtered export of the audit log (point 3b: compliance / auditor
 * pull). Yields the matches **keyset-paginated** as a generator so that even
 * large ranges stream with a low memory footprint (no OFFSET scan, no full
 * load). Output is NDJSON by default, one line per event.
 *
 * The real-time stream for SIEM runs separately over the `audit` log channel
 * ({@see \App\Audit\AuditLogger}); this export is the **targeted pull**
 * (date range, entity, actor).
 */
class AuditExportService
{
    /** Safety net against accidental full dumps (caps the range). */
    public const MAX_ROWS = 500000;
    private const BATCH = 2000;

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /**
     * @param array{from?:?string,to?:?string,action?:?string,entity_type?:?string,
     *     entity_id?:?string,module_key?:?string,actor_user_id?:?string,with_values?:bool} $filters
     * @param ?string $tenantId The caller's tenant, captured by the controller while
     *   the request transaction (and its tenant context) is still live.
     * @return \Generator<int, array<string,mixed>>
     */
    public function stream(array $filters, ?string $tenantId = null, bool $operatorGlobal = false): Generator
    {
        [$where, $params] = $this->where($filters);

        if ($operatorGlobal && $tenantId !== null && $tenantId !== '') {
            // Operator export: own tenant + operator-global (tenant_id IS NULL)
            // platform events. RLS would hide the NULL rows, so read via the
            // privileged (BYPASSRLS) connection and scope IN SQL — other tenants
            // stay invisible. Re-apply the tenant as well: if no privileged
            // connection is configured and this falls back to the RLS 'default'
            // connection, the export still scopes to the operator tenant (the global
            // NULL rows then need a real privileged connection, per the audit_log
            // RLS design) instead of returning nothing.
            $conn = Db::privileged();
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, false)", ['t' => $tenantId]);
            $where[] = '(tenant_id = :op_tenant OR tenant_id IS NULL)';
            $params['op_tenant'] = $tenantId;
        } else {
            // The export body is emitted via a CallbackStream AFTER the request
            // transaction has committed, so the request's SET LOCAL
            // app.current_tenant_id is already gone — core.current_tenant() would be
            // NULL and audit_log's RLS policy (tenant_id = core.current_tenant())
            // would match ZERO rows. Re-apply the captured tenant on this
            // (post-commit, autocommit) connection so the export is correctly scoped
            // to the caller's tenant (peer-review F11/F15).
            $conn = $this->conn();
            if ($tenantId !== null && $tenantId !== '') {
                $conn->execute("SELECT set_config('app.current_tenant_id', :t, false)", ['t' => $tenantId]);
            }
        }

        $withValues = (bool)($filters['with_values'] ?? true);
        $cols = 'id, created_at, actor_user_id, action, entity_type, entity_id, entity_label, '
            . 'module_key, module_name, module_version, component, correlation_id'
            . ($withValues ? ', old_value, new_value' : '');

        $emitted = 0;
        // Keyset cursor over the PK (created_at, id) — stable and index-backed.
        $cursorTs = null;
        $cursorId = null;

        while ($emitted < self::MAX_ROWS) {
            $clauses = $where;
            $p = $params;
            if ($cursorTs !== null) {
                $clauses[] = '(created_at, id) > (:cts, :cid)';
                $p['cts'] = $cursorTs;
                $p['cid'] = $cursorId;
            }
            $sql = "SELECT $cols FROM audit_log"
                . ($clauses !== [] ? ' WHERE ' . implode(' AND ', $clauses) : '')
                . ' ORDER BY created_at, id LIMIT ' . self::BATCH;

            $rows = $conn->execute($sql, $p)->fetchAll('assoc');
            if ($rows === []) {
                return;
            }
            foreach ($rows as $row) {
                if ($withValues) {
                    // jsonb arrives as text — emit it as a parsed structure (NDJSON).
                    $row['old_value'] = $row['old_value'] !== null ? json_decode((string)$row['old_value'], true) : null;
                    $row['new_value'] = $row['new_value'] !== null ? json_decode((string)$row['new_value'], true) : null;
                }
                yield $row;
                $emitted++;
                $cursorTs = $row['created_at'];
                $cursorId = $row['id'];
                if ($emitted >= self::MAX_ROWS) {
                    return;
                }
            }
            if (count($rows) < self::BATCH) {
                return;
            }
        }
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:list<string>,1:array<string,mixed>}
     */
    private function where(array $filters): array
    {
        $where = [];
        $params = [];
        // Time range (ISO-8601 / strtotime-parseable); invalid values are ignored.
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            $raw = trim((string)($filters[$key] ?? ''));
            if ($raw !== '' && strtotime($raw) !== false) {
                $where[] = "created_at $op :$key";
                $params[$key] = $raw;
            }
        }
        if (trim((string)($filters['action'] ?? '')) !== '') {
            $where[] = 'action = :action';
            $params['action'] = trim((string)$filters['action']);
        }
        if (trim((string)($filters['entity_type'] ?? '')) !== '') {
            $where[] = 'entity_type = :etype';
            $params['etype'] = trim((string)$filters['entity_type']);
        }
        if (trim((string)($filters['module_key'] ?? '')) !== '') {
            $where[] = 'module_key = :mkey';
            $params['mkey'] = trim((string)$filters['module_key']);
        }
        if (trim((string)($filters['entity_id'] ?? '')) !== '') {
            $where[] = 'entity_id = :eid';
            $params['eid'] = trim((string)$filters['entity_id']);
        }
        $actor = trim((string)($filters['actor_user_id'] ?? ''));
        if ($actor !== '' && Uuid::isValid($actor)) {
            $where[] = 'actor_user_id = :actor';
            $params['actor'] = $actor;
        }

        return [$where, $params];
    }
}
