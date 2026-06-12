<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\System;

use App\Service\System\MaintenanceMode;
use Cake\TestSuite\TestCase;

/**
 * File-based maintenance-mode switch (restore cutover, ch. 20.1.2/28.11):
 * engage/release are idempotent and survive (as a file) a DB restore.
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

        // Idempotent: a second engage reports "was already active".
        $this->assertFalse(MaintenanceMode::engage('restore'));

        MaintenanceMode::release();
        $this->assertFalse(MaintenanceMode::isFileActive());
    }
}
