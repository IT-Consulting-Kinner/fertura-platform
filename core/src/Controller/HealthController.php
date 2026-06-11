<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Health\HealthService;
use App\Service\Settings\SettingsManager;
use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * Health endpoint (ch. 20.2.1).
 *
 * GET /health        -> public liveness check (only up/down, no detail).
 * GET /health/detail -> token- or session-protected subsystem status (JSON).
 *
 * The detail path is reachable with an authenticated session OR a valid
 * health token (setting core.health_token via header X-Health-Token or
 * query ?token=), so that external monitoring can retrieve details without a
 * login, without exposing internal state in an unauthorized way.
 */
class HealthController extends AppController
{
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
        // Health does not require auth; the detail path checks for itself.
        $this->Authentication->allowUnauthenticated(['index', 'detail', 'ready']);
    }

    public function index(): Response
    {
        $up = (new HealthService())->liveness();

        return $this->jsonResponse(['status' => $up ? 'up' : 'down'], $up ? 200 : 503);
    }

    /**
     * Readiness probe (P15) for rolling/blue-green deployments: 200 = ready
     * for traffic, 503 = drain (e.g. maintenance mode during an update).
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
