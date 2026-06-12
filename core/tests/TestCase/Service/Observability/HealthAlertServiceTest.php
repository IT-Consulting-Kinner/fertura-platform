<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Observability;

use App\Service\Cache\CacheStore;
use App\Service\Health\HealthService;
use App\Service\Http\EgressClient;
use App\Service\Http\EgressResponse;
use App\Service\Observability\HealthAlertService;
use App\Service\Settings\SettingsManager;
use Cake\TestSuite\TestCase;

/**
 * Tests the health alert hook (#12): webhook only on a **status transition**,
 * signed, sent through the egress (stubbed).
 */
class HealthAlertServiceTest extends TestCase
{
    public function testAlertsOnlyOnTransition(): void
    {
        $health = new class extends HealthService {
            public string $st = 'degraded';

            public function __construct()
            {
            }

            public function report(): array
            {
                return [
                    'status' => $this->st,
                    'subsystems' => ['database' => ['status' => 'up'], 'outbox' => ['status' => 'degraded']],
                    'features' => [],
                ];
            }
        };
        $egress = new class extends EgressClient {
            public int $posts = 0;
            public string $lastBody = '';

            public function __construct()
            {
                parent::__construct(null, ['allow_private' => true]);
            }

            public function request(string $method, string $url, array $options = []): EgressResponse
            {
                $this->posts++;
                $this->lastBody = (string)($options['data'] ?? '');

                return new EgressResponse(200, [], '{}');
            }
        };
        $settings = new class extends SettingsManager {
            public function __construct()
            {
            }

            public function get(string $namespace, string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'health.alert_url' => 'http://alerts.local/hook',
                    'health.alert_secret' => 'sek',
                    default => $default,
                };
            }
        };
        $cache = new CacheStore('_app_');
        $cache->delete('health.last_status');

        try {
            $svc = new HealthAlertService($health, $egress, $settings, $cache);

            // up -> degraded: alert.
            $this->assertTrue($svc->check());
            $this->assertSame(1, $egress->posts);
            $this->assertStringContainsString('"status":"degraded"', $egress->lastBody);

            // still degraded -> NO repeated alert.
            $this->assertFalse($svc->check());
            $this->assertSame(1, $egress->posts);

            // Recovery degraded -> up: alert.
            $health->st = 'up';
            $this->assertTrue($svc->check());
            $this->assertSame(2, $egress->posts);
        } finally {
            $cache->delete('health.last_status');
        }
    }
}
