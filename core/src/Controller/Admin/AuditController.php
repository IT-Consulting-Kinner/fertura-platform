<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Infrastructure\Db;
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
        // Per-user list preferences (Paket 2): the filter values and page size
        // are stored and reapplied on the next visit.
        $state = $this->resolveListState('audit', ['action', 'entity_type', 'module_key']);
        $action = trim($state['filters']['action']);
        $entityType = trim($state['filters']['entity_type']);
        $moduleKey = trim($state['filters']['module_key']);

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
        // Operator readers additionally see operator-global (tenant_id IS NULL)
        // platform events — module installs, license/contract registrations, admin
        // grants. The RLS default connection hides those (its policy matches only
        // tenant_id = core.current_tenant()), so operators read via the privileged
        // connection, scoped IN SQL to their own tenant + global so other tenants
        // stay invisible. Tenant admins keep the RLS-scoped default connection.
        $isOperator = $this->isOperatorTenant();
        if ($isOperator) {
            $conn = Db::privileged();
            $where[] = '(a.tenant_id = :op_tenant OR a.tenant_id IS NULL)';
            $params['op_tenant'] = self::OPERATOR_TENANT_ID;
        } else {
            // RLS-effective default connection. Narrow to the concrete Connection so
            // execute() resolves; ConnectionInterface intentionally omits it.
            /** @var \Cake\Database\Connection $conn */
            $conn = ConnectionManager::get('default');
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        // Paginated instead of a hard LIMIT 100 (older entries were only reachable
        // via the NDJSON export). Page size + filters come from the stored list
        // preferences; the filters carry into the page links (view).
        $perPage = $state['per_page'];
        $page = $state['page'];
        $total = (int)($conn->execute('SELECT count(*) c FROM audit_log a' . $whereSql, $params)->fetch('assoc')['c'] ?? 0);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT a.created_at, a.actor_user_id, u.username AS actor_username, a.action, '
            . 'a.entity_type, a.entity_id, a.entity_label, a.module_key, a.component '
            . 'FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id'
            . $whereSql
            . ' ORDER BY a.created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $entries = $conn->execute($sql, $params)->fetchAll('assoc');

        // Filter dropdowns: scope the DISTINCT lists the same way (operator: own
        // tenant + global; tenant admin: RLS-scoped by the connection).
        $scope = $isOperator ? ' WHERE (tenant_id = :op_tenant OR tenant_id IS NULL)' : '';
        $scopeParams = $isOperator ? ['op_tenant' => self::OPERATOR_TENANT_ID] : [];
        $actions = $conn->execute('SELECT DISTINCT action FROM audit_log' . $scope . ' ORDER BY action', $scopeParams)->fetchAll('assoc');
        $entityTypes = $conn->execute('SELECT DISTINCT entity_type FROM audit_log' . $scope . ' ORDER BY entity_type', $scopeParams)->fetchAll('assoc');

        $this->set(compact('entries', 'actions', 'entityTypes', 'action', 'entityType', 'moduleKey', 'page', 'total'));
        $this->set('perPage', $perPage);
        // Pagination links carry the active filters + per_page + the `_lp` marker
        // so paging keeps (and re-persists) the state.
        $this->set('query', $state['query']);
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
        // Capture the tenant NOW (request tx + context live); the CallbackStream
        // body runs after the tx commits, when core.current_tenant() is NULL.
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        $tenantId = (string)($conn->execute('SELECT core.current_tenant() AS t')->fetch('assoc')['t'] ?? '');
        // Operators additionally export operator-global (tenant_id IS NULL) events,
        // matching what /admin/audit shows them; tenant admins stay tenant-scoped.
        $operatorGlobal = $this->isOperatorTenant();
        $body = new CallbackStream(static function () use ($filters, $tenantId, $operatorGlobal): void {
            foreach ((new AuditExportService())->stream($filters, $tenantId, $operatorGlobal) as $row) {
                echo json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            }
        });

        return $this->response
            ->withType('application/x-ndjson')
            ->withDownload('audit-export-' . $stamp . '.ndjson')
            ->withBody($body);
    }
}
