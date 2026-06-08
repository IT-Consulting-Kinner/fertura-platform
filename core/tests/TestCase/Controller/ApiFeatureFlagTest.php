<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Service\Health\HealthService;
use Cake\Routing\Router;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Deployment-Feature-Flags (Kap. 23.3): die externe API lässt sich per
 * `FEATURE_API=false` abschalten — dann existieren keine `/api`-Routen mehr.
 * Zusätzlich weist `/health` die aktiven Flags aus.
 */
class ApiFeatureFlagTest extends TestCase
{
    use IntegrationTestTrait;

    protected function tearDown(): void
    {
        putenv('FEATURE_API');
        unset($_ENV['FEATURE_API'], $_SERVER['FEATURE_API']);
        Router::reload(); // Standard-Routen für nachfolgende Tests wiederherstellen
        parent::tearDown();
    }

    public function testApiRouteExistsByDefault(): void
    {
        Router::reload();
        // Ohne Token -> 401 vom ApiAuthMiddleware (Route existiert).
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/v1/me');
        $this->assertResponseCode(401);
    }

    public function testApiDisabledYields404(): void
    {
        putenv('FEATURE_API=false');
        Router::reload(); // Routen mit gesetztem Flag neu aufbauen
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/v1/me');
        // Keine /api-Route mehr -> 404 (nicht 401).
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
