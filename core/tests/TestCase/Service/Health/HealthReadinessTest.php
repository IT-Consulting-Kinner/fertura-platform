<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Health;

use App\Service\Health\HealthService;
use App\Service\Settings\SettingsManager;
use Cake\TestSuite\TestCase;

/**
 * Test der Readiness-Probe (P15): bereit, wenn DB erreichbar und kein
 * Wartungsmodus; im Wartungsmodus not-ready (Drain für rolling/blue-green).
 */
class HealthReadinessTest extends TestCase
{
    public function testReadyWhenHealthy(): void
    {
        (new SettingsManager())->set('core', 'maintenance_mode', false);
        $r = (new HealthService())->readiness();

        $this->assertTrue($r['ready']);
        $this->assertTrue($r['checks']['database']);
        $this->assertTrue($r['checks']['not_maintenance']);
    }

    public function testNotReadyDuringMaintenance(): void
    {
        $sm = new SettingsManager();
        $prev = (bool)$sm->get('core', 'maintenance_mode', false);
        $sm->set('core', 'maintenance_mode', true);
        try {
            $r = (new HealthService())->readiness();
            $this->assertFalse($r['ready']);
            $this->assertFalse($r['checks']['not_maintenance']);
        } finally {
            $sm->set('core', 'maintenance_mode', $prev);
        }
    }
}
