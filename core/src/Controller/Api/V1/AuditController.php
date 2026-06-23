<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Service\Audit\AuditExportService;
use Cake\Datasource\ConnectionManager;
use Cake\Http\CallbackStream;
use Cake\Http\Response;

/**
 * GET /api/v1/audit — time-range export of the audit log as an **NDJSON stream**
 * (item 3b) for external compliance/SIEM pulls. Scope `audit:read`.
 *
 * Deliberately low-PII, like the stored data (actor by UUID, E16); the value
 * snapshots (`old_value`/`new_value`) can be enabled via `with_values=1` (off by
 * default — the stream is primarily for detection/correlation, not data exfiltration).
 */
class AuditController extends ApiController
{
    public function index(): Response
    {
        if ($denied = $this->requireScope('audit:read')) {
            return $denied;
        }
        $filters = [
            'from' => (string)$this->request->getQuery('from', ''),
            'to' => (string)$this->request->getQuery('to', ''),
            'action' => (string)$this->request->getQuery('action', ''),
            'entity_type' => (string)$this->request->getQuery('entity_type', ''),
            'entity_id' => (string)$this->request->getQuery('entity_id', ''),
            'module_key' => (string)$this->request->getQuery('module_key', ''),
            'actor_user_id' => (string)$this->request->getQuery('actor_user_id', ''),
            'with_values' => $this->request->getQuery('with_values') === '1',
        ];
        // Capture the tenant NOW (request tx + context live); the CallbackStream
        // body runs after the tx commits, when core.current_tenant() is NULL.
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        $tenantId = (string)($conn->execute('SELECT core.current_tenant() AS t')->fetch('assoc')['t'] ?? '');
        $body = new CallbackStream(static function () use ($filters, $tenantId): void {
            foreach ((new AuditExportService())->stream($filters, $tenantId) as $row) {
                echo json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            }
        });

        return $this->response->withType('application/x-ndjson')->withBody($body);
    }
}
