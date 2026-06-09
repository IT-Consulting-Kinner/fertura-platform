<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\System;

use App\Service\System\FeatureFlags;
use Cake\TestSuite\TestCase;

/**
 * Deployment-Feature-Flags (Kap. 23.3): env-basierte Abschaltung optionaler
 * Subsysteme; Standard aktiv, robuste Wert-Interpretation.
 */
class FeatureFlagsTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['FEATURE_API', 'FEATURE_MARKETPLACE', 'FEATURE_BACKUP_SCHEDULER'] as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        parent::tearDown();
    }

    public function testDefaultsEnabled(): void
    {
        $this->assertTrue(FeatureFlags::enabled('api'));
        $this->assertTrue(FeatureFlags::enabled('marketplace'));
        $this->assertTrue(FeatureFlags::enabled('backup_scheduler'));
    }

    public function testUnknownFeatureDefaultsEnabled(): void
    {
        $this->assertTrue(FeatureFlags::enabled('etwas_unbekanntes'));
    }

    public function testFalsyValuesDisable(): void
    {
        foreach (['false', '0', 'off', 'no', 'disabled', 'FALSE', 'Off', ' false ', "false\n"] as $val) {
            putenv("FEATURE_API=$val");
            $this->assertFalse(FeatureFlags::enabled('api'), "Wert '$val' muss deaktivieren.");
        }
    }

    public function testTruthyValuesEnable(): void
    {
        foreach (['true', '1', 'on', 'yes', 'anything'] as $val) {
            putenv("FEATURE_API=$val");
            $this->assertTrue(FeatureFlags::enabled('api'), "Wert '$val' muss aktiv lassen.");
        }
    }

    public function testAllReportsEachFlag(): void
    {
        putenv('FEATURE_MARKETPLACE=false');
        $all = FeatureFlags::all();
        $this->assertArrayHasKey('api', $all);
        $this->assertArrayHasKey('marketplace', $all);
        $this->assertArrayHasKey('backup_scheduler', $all);
        $this->assertTrue($all['api']);
        $this->assertFalse($all['marketplace']);
    }
}
