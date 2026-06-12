<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Metrics;

use App\Infrastructure\Db;
use App\Service\Metrics\MetricsService;
use App\Service\Metrics\PrometheusRenderer;
use Cake\TestSuite\TestCase;

/**
 * Tests the metrics collector (P04): yields at least `fertura_up` and is
 * renderable; partial queries are error-isolated.
 */
class MetricsServiceTest extends TestCase
{
    public function testCollectIncludesUpAndIsRenderable(): void
    {
        $samples = (new MetricsService())->collect();

        $names = array_column($samples, 'name');
        $this->assertContains('fertura_up', $names);

        $text = (new PrometheusRenderer())->render($samples);
        $this->assertStringContainsString("fertura_up 1", $text);
        $this->assertStringStartsWith('#', $text);
    }

    public function testWorkerHeartbeatBecomesAGauge(): void
    {
        // Guards against schema drift: an existing heartbeat MUST appear as a
        // metric (otherwise a broken column would be silently swallowed).
        $conn = Db::privileged();
        $conn->execute('DELETE FROM worker_heartbeats WHERE worker_key = :k', ['k' => 'test:metric']);
        $conn->execute(
            'INSERT INTO worker_heartbeats (worker_key, last_run_at, last_status, detail, updated_at) '
            . "VALUES ('test:metric', now(), 'ok', '{}', now())",
        );
        try {
            $samples = (new MetricsService())->collect();
            $match = array_filter(
                $samples,
                fn ($s) => $s['name'] === 'fertura_worker_heartbeat_age_seconds'
                    && ($s['labels']['worker'] ?? null) === 'test:metric',
            );
            $this->assertCount(1, $match, 'Heartbeat muss als Gauge erscheinen.');
            $sample = array_values($match)[0];
            $this->assertSame('ok', $sample['labels']['status']);
        } finally {
            $conn->execute('DELETE FROM worker_heartbeats WHERE worker_key = :k', ['k' => 'test:metric']);
        }
    }
}
