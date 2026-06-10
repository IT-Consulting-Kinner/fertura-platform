<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Health\HealthService;
use App\Service\Settings\SettingsManager;
use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * Health-Endpoint (Kap. 20.2.1).
 *
 * GET /health        -> öffentlicher Liveness-Check (nur up/down, ohne Detail).
 * GET /health/detail -> token- bzw. session-geschützter Subsystem-Status (JSON).
 *
 * Der Detailpfad ist erreichbar mit angemeldeter Session ODER gültigem
 * Health-Token (Setting core.health_token via Header X-Health-Token oder
 * Query ?token=), damit externes Monitoring ohne Login Details abrufen kann,
 * ohne interne Zustände unautorisiert offenzulegen.
 */
class HealthController extends AppController
{
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
        // Health ist nicht auth-pflichtig; der Detailpfad prüft selbst.
        $this->Authentication->allowUnauthenticated(['index', 'detail', 'ready']);
    }

    public function index(): Response
    {
        $up = (new HealthService())->liveness();

        return $this->jsonResponse(['status' => $up ? 'up' : 'down'], $up ? 200 : 503);
    }

    /**
     * Readiness-Probe (P15) für rollierende/Blue-Green-Deployments: 200 = bereit
     * für Verkehr, 503 = entleeren (z. B. Wartungsmodus während eines Updates).
     */
    public function ready(): Response
    {
        $r = (new HealthService())->readiness();

        return $this->jsonResponse(['ready' => $r['ready'], 'checks' => $r['checks']], $r['ready'] ? 200 : 503);
    }

    public function detail(): Response
    {
        if (!$this->authorizedForDetail()) {
            return $this->jsonResponse(['status' => 'unauthorized'], 401);
        }

        $report = (new HealthService())->report();
        $code = $report['status'] === 'down' ? 503 : 200;

        return $this->jsonResponse($report, $code);
    }

    private function authorizedForDetail(): bool
    {
        if ($this->request->getAttribute('identity') !== null) {
            return true;
        }
        $configured = (string)(new SettingsManager())->get('core', 'health_token', '');
        if ($configured === '') {
            return false;
        }
        $provided = $this->request->getHeaderLine('X-Health-Token');
        if ($provided === '') {
            $provided = (string)$this->request->getQuery('token');
        }

        return $provided !== '' && hash_equals($configured, $provided);
    }

    /** @param array<string, mixed> $payload */
    private function jsonResponse(array $payload, int $status): Response
    {
        return $this->response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody((string)json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            ));
    }
}
