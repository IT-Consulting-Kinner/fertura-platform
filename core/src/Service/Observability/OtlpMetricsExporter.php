<?php
declare(strict_types=1);

namespace App\Service\Observability;

use App\Service\Http\EgressClient;
use App\Service\Metrics\MetricsService;
use Cake\Log\Log;
use Throwable;
use function Cake\Core\env;

/**
 * Lightweight **OTLP/HTTP metrics exporter** (#12): exports the existing gauges
 * from {@see MetricsService} in OpenTelemetry format to any OTLP collector
 * (`OTEL_EXPORTER_OTLP_ENDPOINT`), without a heavy SDK. Backend-/lock-in-free —
 * any OTel-capable stack (Collector, Grafana, Datadog …) can ingest it.
 *
 * Pushed via the hardened {@see EgressClient}; only active when an endpoint is
 * set. Traces stay correlatable via `traceparent`/logs (no span model here).
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

    /** Exports a snapshot; returns true if sent (2xx). */
    public function export(): bool
    {
        $endpoint = $this->endpoint();
        if ($endpoint === null) {
            return false;
        }
        try {
            $resp = $this->egress->postJson($endpoint, $this->payload($this->metrics->collect()));

            return $resp->isSuccess();
        } catch (Throwable $e) {
            // Make it visible instead of silently swallowing: an internal collector
            // typically sits on a private IP and is blocked by the egress SSRF
            // protection until it is in core.http.egress.allowlist (see SCALING.md).
            Log::warning('[otlp-export] Export fehlgeschlagen (' . $endpoint . '): ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Builds the OTLP/HTTP JSON body (resourceMetrics → scopeMetrics → metrics).
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
