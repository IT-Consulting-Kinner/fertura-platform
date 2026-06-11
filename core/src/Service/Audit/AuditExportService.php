<?php
declare(strict_types=1);

namespace App\Service\Audit;

use App\Infrastructure\Uuid;
use Cake\Datasource\ConnectionManager;
use Generator;

/**
 * Zeitbereichs-/gefilterter Export des Audit-Logs (Punkt 3b: Compliance-/
 * Auditor-Pull). Liefert die Treffer **keyset-paginiert** als Generator, damit
 * auch große Bereiche speicherschonend gestreamt werden (kein OFFSET-Scan, kein
 * Voll-Load). Standard-NDJSON, eine Zeile je Ereignis.
 *
 * Real-time-Strom für SIEM läuft separat über den `audit`-Log-Kanal
 * ({@see \App\Audit\AuditLogger}); dieser Export ist der **gezielte Pull**
 * (Datumsbereich, Entität, Akteur).
 */
class AuditExportService
{
    /** Sicherheitsnetz gegen unbeabsichtigte Voll-Dumps (Bereich eingrenzen). */
    public const MAX_ROWS = 500000;
    private const BATCH = 2000;

    private function conn(): \Cake\Database\Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /**
     * @param array{from?:?string,to?:?string,action?:?string,entity_type?:?string,
     *     entity_id?:?string,module_key?:?string,actor_user_id?:?string,with_values?:bool} $filters
     * @return Generator<int, array<string,mixed>>
     */
    public function stream(array $filters): Generator
    {
        $withValues = (bool)($filters['with_values'] ?? true);
        $cols = 'id, created_at, actor_user_id, action, entity_type, entity_id, entity_label, '
            . 'module_key, module_name, module_version, component, correlation_id'
            . ($withValues ? ', old_value, new_value' : '');

        [$where, $params] = $this->where($filters);
        $emitted = 0;
        // Keyset-Cursor über den PK (created_at, id) — stabil + indexgestützt.
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

            $rows = $this->conn()->execute($sql, $p)->fetchAll('assoc');
            if ($rows === []) {
                return;
            }
            foreach ($rows as $row) {
                if ($withValues) {
                    // jsonb kommt als Text — als geparste Struktur ausgeben (NDJSON).
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
        // Zeitbereich (ISO-8601/strtotime-fähig); ungültige Werte werden ignoriert.
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
