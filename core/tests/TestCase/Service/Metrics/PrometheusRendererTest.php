<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Metrics;

use App\Service\Metrics\PrometheusRenderer;
use Cake\TestSuite\TestCase;

/**
 * Test des Prometheus-Textrenderers (P04): HELP/TYPE-Gruppierung, Labels,
 * Escaping, Zahlformat.
 */
class PrometheusRendererTest extends TestCase
{
    public function testRendersHelpTypeAndSamples(): void
    {
        $out = (new PrometheusRenderer())->render([
            ['name' => 'fertura_up', 'type' => 'gauge', 'help' => 'Liveness.', 'labels' => [], 'value' => 1],
            ['name' => 'm', 'type' => 'gauge', 'help' => 'H', 'labels' => ['a' => 'x', 'b' => 'y'], 'value' => 3],
            ['name' => 'm', 'type' => 'gauge', 'help' => 'H', 'labels' => ['a' => 'z'], 'value' => 4],
        ]);

        $this->assertStringContainsString("# HELP fertura_up Liveness.", $out);
        $this->assertStringContainsString("# TYPE fertura_up gauge", $out);
        $this->assertStringContainsString("fertura_up 1", $out);
        // HELP/TYPE pro Metrik nur einmal.
        $this->assertSame(1, substr_count($out, '# TYPE m gauge'));
        $this->assertStringContainsString('m{a="x",b="y"} 3', $out);
        $this->assertStringContainsString('m{a="z"} 4', $out);
    }

    public function testEscapesLabelValues(): void
    {
        $out = (new PrometheusRenderer())->render([
            ['name' => 'm', 'labels' => ['l' => 'a"b\\c'], 'value' => 1],
        ]);
        $this->assertStringContainsString('m{l="a\\"b\\\\c"} 1', $out);
    }

    public function testFloatFormatting(): void
    {
        $out = (new PrometheusRenderer())->render([['name' => 'm', 'labels' => [], 'value' => 1.5]]);
        $this->assertStringContainsString("m 1.5", $out);
    }
}
