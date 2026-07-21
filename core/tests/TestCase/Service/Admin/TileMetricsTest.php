<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Admin;

use App\Service\Admin\TileMetrics;
use Cake\I18n\I18n;
use Cake\TestSuite\TestCase;

/**
 * Uniform admin-nav tile metrics: every collection tile gets badge = active count
 * + detail = "x aktiv / y inaktiv"; single-figure tiles (queue / licenses) keep a
 * consistent, non-empty detail.
 */
class TileMetricsTest extends TestCase
{
    private string $locale = 'en_US';

    protected function setUp(): void
    {
        parent::setUp();
        $this->locale = I18n::getLocale();
        I18n::setLocale('de_DE');
    }

    protected function tearDown(): void
    {
        I18n::setLocale($this->locale);
        parent::tearDown();
    }

    public function testActiveInactiveFormatsUniformly(): void
    {
        $this->assertSame(['badge' => '3', 'detail' => '3 aktiv / 2 inaktiv'], TileMetrics::activeInactive(3, 2));
        // Zero inactive still shows the uniform two-part detail (no bare/empty tile).
        $this->assertSame(['badge' => '1', 'detail' => '1 aktiv / 0 inaktiv'], TileMetrics::activeInactive(1, 0));
    }

    public function testActiveInactiveClampsNegativeInactive(): void
    {
        // A racing count where total < active must never render a negative figure.
        $this->assertSame('5 aktiv / 0 inaktiv', TileMetrics::activeInactive(5, -2)['detail']);
    }

    public function testSingleFormatsCountWithLabel(): void
    {
        $this->assertSame(['badge' => '7', 'detail' => '7 ausstehend'], TileMetrics::single(7, 'admin.metric.pending'));
        $this->assertSame(['badge' => '4', 'detail' => '4 insgesamt'], TileMetrics::single(4, 'admin.metric.total'));
    }
}
