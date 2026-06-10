<?php
declare(strict_types=1);

namespace App\Service\Observability;

use App\Service\Cache\CacheStore;
use App\Service\Health\HealthService;
use App\Service\Http\EgressClient;
use App\Service\Settings\SettingsManager;
use Throwable;

/**
 * Health-Alert-Hook (#12): meldet **Statuswechsel** des Gesamt-Health
 * (`up` ↔ `degraded`/`down`) per signiertem Webhook an `health.alert_url`. Nur bei
 * **Übergang** (kein Spam pro Zyklus); Signatur HMAC-SHA256 (`health.alert_secret`),
 * Versand über den gehärteten {@see EgressClient}. Routing/Eskalation übernimmt der
 * externe Empfänger (Alertmanager/PagerDuty/…).
 */
class HealthAlertService
{
    private const STATE_KEY = 'health.last_status';

    public function __construct(
        private ?HealthService $health = null,
        private ?EgressClient $egress = null,
        private ?SettingsManager $settings = null,
        private ?CacheStore $cache = null,
    ) {
        $this->health ??= new HealthService();
        $this->egress ??= new EgressClient();
        $this->settings ??= new SettingsManager();
        $this->cache ??= new CacheStore('_app_');
    }

    /**
     * Prüft den Health-Status und alarmiert bei Übergang. Gibt true zurück, wenn
     * ein Alarm gesendet wurde.
     */
    public function check(): bool
    {
        $report = $this->health->report();
        $status = (string)($report['status'] ?? 'up');
        $previous = (string)($this->cache->get(self::STATE_KEY, 'up'));

        if ($status === $previous) {
            return false; // kein Wechsel -> kein Alarm
        }
        $this->cache->set(self::STATE_KEY, $status);

        $url = (string)($this->settings->get('core', 'health.alert_url', '') ?? '');
        if ($url === '') {
            return false; // Wechsel registriert, aber kein Empfänger konfiguriert
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
        } catch (Throwable) {
            return false;
        }
    }
}
