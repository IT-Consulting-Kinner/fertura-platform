<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Sdk;

use App\Service\Sdk\ManifestLinter;
use Cake\TestSuite\TestCase;

/**
 * Test des Manifest-Linters (P16).
 */
class ManifestLinterTest extends TestCase
{
    private function valid(): array
    {
        return [
            'id' => 'demo_modul',
            'name' => 'Demo',
            'version' => '1.0.0',
            'type' => 'main',
            'edition' => 'free',
            'description' => 'x',
            'core_compatibility' => '>=1.0.0 <2.0.0',
            'publisher' => 'Acme',
            'php_namespace' => 'Acme\\Demo',
            'api_routes' => [['method' => 'GET', 'path' => '/ping', 'class' => 'Acme\\Demo\\PingEndpoint']],
        ];
    }

    public function testValidManifestHasNoErrors(): void
    {
        $r = (new ManifestLinter())->lint($this->valid());
        $this->assertSame([], $r['errors']);
    }

    public function testMissingRequiredFields(): void
    {
        $m = $this->valid();
        unset($m['publisher'], $m['php_namespace']);
        $r = (new ManifestLinter())->lint($m);
        $this->assertNotEmpty($r['errors']);
        $this->assertTrue((bool)array_filter($r['errors'], fn ($e) => str_contains($e, 'publisher')));
    }

    public function testInvalidIdAndApiRoute(): void
    {
        $m = $this->valid();
        $m['id'] = 'Bad-Id';
        $m['api_routes'] = [['method' => 'FETCH', 'path' => 'ping', 'class' => 'X']];
        $r = (new ManifestLinter())->lint($m);
        $this->assertTrue((bool)array_filter($r['errors'], fn ($e) => str_contains($e, 'id ungültig')));
        $this->assertTrue((bool)array_filter($r['errors'], fn ($e) => str_contains($e, 'method')));
        $this->assertTrue((bool)array_filter($r['errors'], fn ($e) => str_contains($e, "mit '/' beginnen")));
    }

    public function testClassOutsideNamespaceIsWarning(): void
    {
        $m = $this->valid();
        $m['collectors_registered'] = [['contract' => 'core.collector.scheduled', 'class' => 'Other\\Task']];
        $r = (new ManifestLinter())->lint($m);
        $this->assertSame([], $r['errors']);
        $this->assertNotEmpty($r['warnings']);
    }
}
