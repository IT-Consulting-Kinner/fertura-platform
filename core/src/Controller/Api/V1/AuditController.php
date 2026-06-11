<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Service\Audit\AuditExportService;
use Cake\Http\CallbackStream;
use Cake\Http\Response;

/**
 * GET /api/v1/audit — Zeitbereichs-Export des Audit-Logs als **NDJSON-Strom**
 * (Punkt 3b) für externe Compliance-/SIEM-Pulls. Scope `audit:read`.
 *
 * Bewusst PII-arm wie der DB-Bestand (Akteur per UUID, E16); die Wert-Snapshots
 * (`old_value`/`new_value`) sind über `with_values=1` zuschaltbar (Standard aus
 * — der Strom ist primär für Detektion/Korrelation, nicht für Daten-Exfiltration).
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
        $body = new CallbackStream(static function () use ($filters): void {
            foreach ((new AuditExportService())->stream($filters) as $row) {
                echo json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            }
        });

        return $this->response->withType('application/x-ndjson')->withBody($body);
    }
}
