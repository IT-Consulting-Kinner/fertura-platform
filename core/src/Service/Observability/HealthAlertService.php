<?php
declare(strict_types=1);

namespace App\Service\Observability;

use App\Service\Cache\CacheStore;
use App\Service\Health\HealthService;
use App\Service\Http\EgressClient;
use App\Service\Settings\SettingsManager;
use Cake\Log\Log;
use Throwable;

/**
 * Health alert hook (#12): reports **status changes** of the overall health
 * (`up` ↔ `degraded`/`down`) via a signed webhook to `health.alert_url`. Only on
 * **transition** (no spam every cycle); signature HMAC-SHA256 (`health.alert_secret`),
 * sent via the hardened {@see EgressClient}. Routing/escalation is handled by the
 * external recipient (Alertmanager/PagerDuty/…).
 */
class HealthAlertService
{
    private const STATE_KEY = 'health.last_status';

    private HealthService $health;
    private EgressClient $egress;
    private SettingsManager $settings;
    private CacheStore $cache;

    public function __construct(
        ?HealthService $health = null,
        ?EgressClient $egress = null,
        ?SettingsManager $settings = null,
        ?CacheStore $cache = null,
    ) {
        $this->health = $health ?? new HealthService();
        $this->egress = $egress ?? new EgressClient();
        $this->settings = $settings ?? new SettingsManager();
        // Long-lived state cache (no +1h TTL): otherwise an outage lasting >1h would
        // wrongly re-trigger the same alert once the key expires.
        $this->cache = $cache ?? new CacheStore('_app_health_');
    }

    /**
     * Checks the health status and alerts on transition. Returns true if an
     * alert was sent.
     */
    public function check(): bool
    {
        $report = $this->health->report();
        $status = (string)($report['status'] ?? 'up');
        $previous = (string)($this->cache->get(self::STATE_KEY, 'up'));

        if ($status === $previous) {
            return false; // no change -> no alert
        }
        $this->cache->set(self::STATE_KEY, $status);

        $url = (string)($this->settings->get('core', 'health.alert_url', '') ?? '');
        if ($url === '') {
            return false; // change registered, but no recipient configured
        }

        try {
            $subStatuses = [];
            foreach ((array)($report['subsystems'] ?? []) as $name => $sub) {
                $subStatuses[(string)$name] = is_array($sub) ? (string)($sub['status'] ?? '?') : '?';
            }
            $body = (string)json_encode([
                'event' => 'health.status_change',
                'status' => $status,
                'previous' => $previous,
                'subsystems' => $subStatuses,
            ]);
            $headers = ['Content-Type' => 'application/json'];
            $secret = (string)($this->settings->get('core', 'health.alert_secret', '') ?? '');
            if ($secret !== '') {
                $ts = (string)time();
                $headers['X-Fertura-Timestamp'] = $ts;
                $headers['X-Fertura-Signature'] = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
            }
            $resp = $this->egress->request('POST', $url, ['headers' => $headers, 'data' => $body]);

            return $resp->isSuccess();
        } catch (Throwable $e) {
            // Make it visible (no silent swallowing): an internal alert recipient
            // on a private IP is blocked by the egress SSRF protection until it is
            // in core.http.egress.allowlist. No body/secret in the log.
            Log::warning('[health-alert] Zustellung fehlgeschlagen (' . $url . '): ' . $e->getMessage());

            return false;
        }
    }
}
