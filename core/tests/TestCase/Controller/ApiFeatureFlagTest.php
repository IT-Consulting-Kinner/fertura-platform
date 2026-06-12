<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Service\Health\HealthService;
use Cake\Routing\Router;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Deployment feature flags (ch. 23.3): the external API can be switched off via
 * `FEATURE_API=false` — then no `/api` routes exist anymore. In addition,
 * `/health` reports the active flags.
 */
class ApiFeatureFlagTest extends TestCase
{
    use IntegrationTestTrait;

    protected function tearDown(): void
    {
        putenv('FEATURE_API');
        unset($_ENV['FEATURE_API'], $_SERVER['FEATURE_API']);
        Router::reload(); // restore default routes for subsequent tests
        parent::tearDown();
    }

    public function testApiRouteExistsByDefault(): void
    {
        Router::reload();
        // Without a token -> 401 from ApiAuthMiddleware (route exists).
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/v1/me');
        $this->assertResponseCode(401);
    }

    public function testApiDisabledYields404(): void
    {
        putenv('FEATURE_API=false');
        Router::reload(); // rebuild routes with the flag set
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/v1/me');
        // No more /api route -> 404 (not 401).
        $this->assertResponseCode(404);
    }

    public function testHealthReportExposesFeatureFlags(): void
    {
        $report = (new HealthService())->report();
        $this->assertArrayHasKey('features', $report);
        $this->assertArrayHasKey('api', $report['features']);
        $this->assertArrayHasKey('marketplace', $report['features']);
        $this->assertArrayHasKey('backup_scheduler', $report['features']);
    }
}
