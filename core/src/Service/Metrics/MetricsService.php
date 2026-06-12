<?php
declare(strict_types=1);

namespace App\Service\Metrics;

use App\Infrastructure\Db;
use Throwable;

/**
 * Collects platform metrics (program Tier-3, P04) from **shared DB state** and
 * exports them as Prometheus gauges.
 *
 * Deliberately state-based (rather than process-local request counters): the
 * source is the DB (worker heartbeats, outbox, modules), which all instances
 * share — this sidesteps the cross-process aggregation problem of PHP-FPM. Each
 * sub-query is error-isolated; a failure only drops its own metrics.
 *
 * @return list<array<string,mixed>>
 */
class MetricsService
{
    /**
     * @return list<array<string,mixed>>
     */
    public function collect(): array
    {
        $samples = [[
            'name' => 'fertura_up',
            'type' => 'gauge',
            'help' => 'Liveness der Core-Instanz (1 = up).',
            'labels' => [],
            'value' => 1,
        ]];

        // Privileged connection: the /metrics endpoint runs in the request path
        // as the NOBYPASSRLS app role, which is not allowed to read system/
        // operational tables (worker_heartbeats/event_outbox/modules). As in
        // HealthService, we read the operational state with privilege.
        $conn = Db::privileged();

        $this->safe($samples, function () use ($conn): array {
            $rows = $conn->execute(
                'SELECT worker_key, last_status, EXTRACT(EPOCH FROM (now() - last_run_at))::int AS age '
                . 'FROM worker_heartbeats',
            )->fetchAll('assoc');
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'name' => 'fertura_worker_heartbeat_age_seconds',
                    'type' => 'gauge',
                    'help' => 'Alter des letzten Worker-Heartbeats in Sekunden.',
                    'labels' => ['worker' => (string)$r['worker_key'], 'status' => (string)$r['last_status']],
                    'value' => (int)$r['age'],
                ];
            }

            return $out;
        });

        $this->safe($samples, fn(): array => $this->grouped(
            $conn,
            'event_outbox',
            'fertura_outbox_events',
            'Ereignisse in der Outbox nach Status.',
        ));
        $this->safe($samples, fn(): array => $this->grouped(
            $conn,
            'modules',
            'fertura_modules',
            'Installierte Module nach Status.',
        ));

        return $samples;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function grouped(object $conn, string $table, string $metric, string $help): array
    {
        // $table/$metric are fixed literals (no user input) -> no injection risk.
        $rows = $conn->execute("SELECT status, count(*) AS c FROM $table GROUP BY status")->fetchAll('assoc');
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name' => $metric,
                'type' => 'gauge',
                'help' => $help,
                'labels' => ['status' => (string)$r['status']],
                'value' => (int)$r['c'],
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @param callable():list<array<string,mixed>> $fn
     */
    private function safe(array &$samples, callable $fn): void
    {
        try {
            foreach ($fn() as $sample) {
                $samples[] = $sample;
            }
        } catch (Throwable) {
            // Sub-metric unavailable -> skip it, the endpoint stays usable.
        }
    }
}
