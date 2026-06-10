<?php
declare(strict_types=1);

namespace App\Service\Observability;

use App\Service\Http\EgressClient;
use App\Service\Metrics\MetricsService;
use Throwable;
use function Cake\Core\env;

/**
 * Schlanker **OTLP/HTTP-Metrik-Exporter** (#12): exportiert die vorhandenen
 * Gauges aus {@see MetricsService} im OpenTelemetry-Format an einen beliebigen
 * OTLP-Collector (`OTEL_EXPORTER_OTLP_ENDPOINT`), ohne schweres SDK. Backend-/
 * Lock-in-frei — jeder OTel-fähige Stack (Collector, Grafana, Datadog …) ingestiert.
 *
 * Push über den gehärteten {@see EgressClient}; nur aktiv, wenn ein Endpoint gesetzt
 * ist. Traces bleiben über `traceparent`/Logs korrelierbar (kein Span-Modell hier).
 */
class OtlpMetricsExporter
{
    private EgressClient $egress;
    private MetricsService $metrics;

    public function __construct(
        ?EgressClient $egress = null,
        ?MetricsService $metrics = null,
    ) {
        $this->egress = $egress ?? new EgressClient();
        $this->metrics = $metrics ?? new MetricsService();
    }

    public function endpoint(): ?string
    {
        $base = (string)(env('OTEL_EXPORTER_OTLP_ENDPOINT') ?: '');

        return $base !== '' ? rtrim($base, '/') . '/v1/metrics' : null;
    }

    public function available(): bool
    {
        return $this->endpoint() !== null;
    }

    /** Exportiert einen Snapshot; gibt true zurück, wenn gesendet (2xx). */
    public function export(): bool
    {
        $endpoint = $this->endpoint();
        if ($endpoint === null) {
            return false;
        }
        try {
            $resp = $this->egress->postJson($endpoint, $this->payload($this->metrics->collect()));

            return $resp->isSuccess();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Baut den OTLP/HTTP-JSON-Body (resourceMetrics → scopeMetrics → metrics).
     *
     * @param list<array<string,mixed>> $samples
     * @return array<string,mixed>
     */
    public function payload(array $samples): array
    {
        $nowNano = (string)(int)(microtime(true) * 1_000_000_000);
        $metrics = [];
        foreach ($samples as $s) {
            $attrs = [];
            foreach ((array)($s['labels'] ?? []) as $k => $v) {
                $attrs[] = ['key' => (string)$k, 'value' => ['stringValue' => (string)$v]];
            }
            $metrics[] = [
                'name' => (string)($s['name'] ?? ''),
                'description' => (string)($s['help'] ?? ''),
                'gauge' => [
                    'dataPoints' => [[
                        'asDouble' => (float)($s['value'] ?? 0),
                        'timeUnixNano' => $nowNano,
                        'attributes' => $attrs,
                    ]],
                ],
            ];
        }

        return [
            'resourceMetrics' => [[
                'resource' => [
                    'attributes' => [
                        ['key' => 'service.name', 'value' => ['stringValue' => 'fertura-core']],
                    ],
                ],
                'scopeMetrics' => [[
                    'scope' => ['name' => 'fertura'],
                    'metrics' => $metrics,
                ]],
            ]],
        ];
    }
}
