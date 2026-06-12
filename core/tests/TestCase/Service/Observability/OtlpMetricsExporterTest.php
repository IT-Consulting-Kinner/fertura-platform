<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Observability;

use App\Service\Http\EgressClient;
use App\Service\Http\EgressResponse;
use App\Service\Metrics\MetricsService;
use App\Service\Observability\OtlpMetricsExporter;
use Cake\TestSuite\TestCase;

/**
 * Tests the lightweight OTLP/HTTP metrics exporter (#12): OTLP payload structure
 * and dispatch to `OTEL_EXPORTER_OTLP_ENDPOINT` (stubbed, no real HTTP traffic).
 */
class OtlpMetricsExporterTest extends TestCase
{
    public function testPayloadHasOtlpStructure(): void
    {
        $p = (new OtlpMetricsExporter())->payload([
            ['name' => 'fertura_up', 'type' => 'gauge', 'help' => 'h', 'labels' => ['a' => 'b'], 'value' => 1],
        ]);
        $rm = $p['resourceMetrics'][0];
        $this->assertSame('fertura-core', $rm['resource']['attributes'][0]['value']['stringValue']);
        $m = $rm['scopeMetrics'][0]['metrics'][0];
        $this->assertSame('fertura_up', $m['name']);
        $this->assertSame(1.0, $m['gauge']['dataPoints'][0]['asDouble']);
        $this->assertSame('a', $m['gauge']['dataPoints'][0]['attributes'][0]['key']);
        $this->assertSame('b', $m['gauge']['dataPoints'][0]['attributes'][0]['value']['stringValue']);
    }

    public function testExportPostsToCollectorWhenConfigured(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318');
        try {
            $egress = new class extends EgressClient {
                /**
                 * @var list<array<string,mixed>>
                 */
                public array $calls = [];

                public function __construct()
                {
                    parent::__construct(null, ['allow_private' => true]);
                }

                public function postJson(string $url, array $payload, array $options = []): EgressResponse
                {
                    $this->calls[] = ['url' => $url, 'payload' => $payload];

                    return new EgressResponse(200, [], '{}');
                }
            };
            $metrics = new class extends MetricsService {
                public function collect(): array
                {
                    return [['name' => 'x', 'type' => 'gauge', 'help' => '', 'labels' => [], 'value' => 5]];
                }
            };
            $e = new OtlpMetricsExporter($egress, $metrics);

            $this->assertTrue($e->available());
            $this->assertTrue($e->export());
            $this->assertSame('http://collector:4318/v1/metrics', $egress->calls[0]['url']);
            $this->assertSame(
                'x',
                $egress->calls[0]['payload']['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0]['name'],
            );
        } finally {
            putenv('OTEL_EXPORTER_OTLP_ENDPOINT');
        }
    }

    public function testDisabledWithoutEndpoint(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT');
        $this->assertFalse((new OtlpMetricsExporter())->available());
    }
}
