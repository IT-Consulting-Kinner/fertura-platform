<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Audit\AuditExportService;
use Cake\Datasource\ConnectionManager;
use Cake\Http\CallbackStream;
use Cake\Http\Response;

/**
 * Audit log inspection (available to any administrator, no specific area).
 *
 * Read-only and filtered. Personal data stays referenced by UUID (E16);
 * the username is resolved for display only, not read from the log itself.
 */
class AuditController extends AdminController
{
    protected ?string $requiredArea = null;

    public function index(): void
    {
        $action = trim((string)$this->request->getQuery('action'));
        $entityType = trim((string)$this->request->getQuery('entity_type'));
        $moduleKey = trim((string)$this->request->getQuery('module_key'));

        $where = [];
        $params = [];
        if ($action !== '') {
            $where[] = 'a.action ILIKE :action';
            $params['action'] = '%' . $action . '%';
        }
        if ($entityType !== '') {
            $where[] = 'a.entity_type = :etype';
            $params['etype'] = $entityType;
        }
        if ($moduleKey !== '') {
            $where[] = 'a.module_key = :mkey';
            $params['mkey'] = $moduleKey;
        }
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        // RLS-effective default connection. Narrow to the concrete Connection so
        // execute() resolves; ConnectionInterface intentionally omits it.
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        // Paginated instead of a hard LIMIT 100 (older entries were only reachable
        // via the NDJSON export). The filters carry into the page links (view).
        $perPage = 50;
        $page = max(1, (int)$this->request->getQuery('page', 1));
        $total = (int)($conn->execute('SELECT count(*) c FROM audit_log a' . $whereSql, $params)->fetch('assoc')['c'] ?? 0);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT a.created_at, a.actor_user_id, u.username AS actor_username, a.action, '
            . 'a.entity_type, a.entity_id, a.entity_label, a.module_key, a.component '
            . 'FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id'
            . $whereSql
            . ' ORDER BY a.created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $entries = $conn->execute($sql, $params)->fetchAll('assoc');

        $actions = $conn->execute('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll('assoc');
        $entityTypes = $conn->execute('SELECT DISTINCT entity_type FROM audit_log ORDER BY entity_type')->fetchAll('assoc');

        $this->set(compact('entries', 'actions', 'entityTypes', 'action', 'entityType', 'moduleKey', 'page', 'total'));
        $this->set('perPage', $perPage);
        $this->set('query', $this->request->getQueryParams());
    }

    /**
     * Time-range export (item 3b) as an **NDJSON download** for compliance/
     * auditor pulls — including the value snapshots (the administrator is authorized).
     * Query filters: from/to (ISO date), action, entity_type, entity_id,
     * module_key, actor_user_id. Keyset-streamed (memory-efficient).
     */
    public function export(): Response
    {
        $filters = [
            'from' => (string)$this->request->getQuery('from', ''),
            'to' => (string)$this->request->getQuery('to', ''),
            'action' => (string)$this->request->getQuery('action', ''),
            'entity_type' => (string)$this->request->getQuery('entity_type', ''),
            'entity_id' => (string)$this->request->getQuery('entity_id', ''),
            'module_key' => (string)$this->request->getQuery('module_key', ''),
            'actor_user_id' => (string)$this->request->getQuery('actor_user_id', ''),
            'with_values' => true,
        ];
        $stamp = date('Ymd_His');
        $body = new CallbackStream(static function () use ($filters): void {
            foreach ((new AuditExportService())->stream($filters) as $row) {
                echo json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            }
        });

        return $this->response
            ->withType('application/x-ndjson')
            ->withDownload('audit-export-' . $stamp . '.ndjson')
            ->withBody($body);
    }
}
