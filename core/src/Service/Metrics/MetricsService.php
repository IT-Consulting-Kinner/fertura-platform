<?php
declare(strict_types=1);

namespace App\Service\Metrics;

use App\Infrastructure\Db;
use Throwable;

/**
 * Sammelt Plattform-Metriken (Programm Tier-3, P04) aus **gemeinsam geteiltem
 * DB-Zustand** und exportiert sie als Prometheus-Gauges.
 *
 * Bewusst zustandsbasiert (statt prozesslokaler Request-Zähler): Die Quelle ist
 * die DB (Worker-Heartbeats, Outbox, Module), die alle Instanzen teilen — damit
 * entfällt das prozessübergreifende Aggregationsproblem von PHP-FPM. Jede
 * Teil-Abfrage ist fehlerisoliert; ein Fehler entfernt nur ihre Metriken.
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

        // Privilegierte Verbindung: der /metrics-Endpoint läuft im Request-Pfad
        // als NOBYPASSRLS-App-Rolle, die System-/Betriebstabellen
        // (worker_heartbeats/event_outbox/modules) nicht lesen darf. Wie
        // HealthService lesen wir den Betriebszustand privilegiert.
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

        $this->safe($samples, fn (): array => $this->grouped(
            $conn,
            'event_outbox',
            'fertura_outbox_events',
            'Ereignisse in der Outbox nach Status.',
        ));
        $this->safe($samples, fn (): array => $this->grouped(
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
        // $table/$metric sind feste Literale (keine Nutzereingabe) -> keine Injection.
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
            // Teil-Metrik nicht verfügbar -> auslassen, Endpoint bleibt nutzbar.
        }
    }
}
