<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\System;

use App\Service\System\MaintenanceMode;
use Cake\TestSuite\TestCase;

/**
 * Datei-basierter Wartungsmodus-Schalter (Restore-Cutover, Kap. 20.1.2/28.11):
 * engage/release sind idempotent und überleben (als Datei) einen DB-Restore.
 */
class MaintenanceModeTest extends TestCase
{
    protected function tearDown(): void
    {
        MaintenanceMode::release();
        parent::tearDown();
    }

    public function testEngageAndRelease(): void
    {
        $this->assertFalse(MaintenanceMode::isFileActive());
        $this->assertTrue(MaintenanceMode::engage('restore'), 'Erstes engage setzt neu.');
        $this->assertTrue(MaintenanceMode::isFileActive());
        $this->assertFileExists(MaintenanceMode::flagPath());

        // Idempotent: zweites engage meldet "war schon aktiv".
        $this->assertFalse(MaintenanceMode::engage('restore'));

        MaintenanceMode::release();
        $this->assertFalse(MaintenanceMode::isFileActive());
    }
}
