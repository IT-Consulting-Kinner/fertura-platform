<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Metrics\MetricsService;
use App\Service\Metrics\PrometheusRenderer;
use App\Service\Settings\SettingsManager;
use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * Prometheus-Metrik-Endpoint (Programm Tier-3, P04).
 *
 * GET /metrics -> Prometheus-Textformat. Geschützt wie der Health-Detailpfad:
 * angemeldete Session ODER gültiges Health-Token (`core.health_token` via Header
 * `X-Health-Token` oder Query `?token=`) — damit externes Monitoring/Prometheus
 * ohne Login scrapen kann, ohne den Zustand unautorisiert offenzulegen.
 */
class MetricsController extends AppController
{
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['index']);
    }

    public function index(): Response
    {
        if (!$this->authorized()) {
            return $this->response->withStatus(401)->withType('text/plain')->withStringBody("unauthorized\n");
        }

        $text = (new PrometheusRenderer())->render((new MetricsService())->collect());

        return $this->response
            ->withType('text/plain; version=0.0.4; charset=utf-8')
            ->withStringBody($text);
    }

    private function authorized(): bool
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
}
