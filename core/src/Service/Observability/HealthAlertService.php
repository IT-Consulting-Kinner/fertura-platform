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
 * Health-Alert-Hook (#12): meldet **Statuswechsel** des Gesamt-Health
 * (`up` ↔ `degraded`/`down`) per signiertem Webhook an `health.alert_url`. Nur bei
 * **Übergang** (kein Spam pro Zyklus); Signatur HMAC-SHA256 (`health.alert_secret`),
 * Versand über den gehärteten {@see EgressClient}. Routing/Eskalation übernimmt der
 * externe Empfänger (Alertmanager/PagerDuty/…).
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
        // Langlebiger Zustands-Cache (kein +1h-TTL): sonst löst eine >1h anhaltende
        // Störung nach Ablauf des Schlüssels denselben Alarm fälschlich erneut aus.
        $this->cache = $cache ?? new CacheStore('_app_health_');
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
        } catch (Throwable $e) {
            // Sichtbar machen (kein stilles Schlucken): ein interner Alert-Empfänger
            // auf privater IP wird vom Egress-SSRF-Schutz blockiert, bis er in
            // core.http.egress.allowlist steht. Kein Body/Secret im Log.
            Log::warning('[health-alert] Zustellung fehlgeschlagen (' . $url . '): ' . $e->getMessage());

            return false;
        }
    }
}
