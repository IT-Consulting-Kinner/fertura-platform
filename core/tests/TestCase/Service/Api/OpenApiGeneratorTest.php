<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Api;

use App\Service\Api\ApiRouteRegistry;
use App\Service\Api\OpenApiGenerator;
use Cake\TestSuite\TestCase;

/**
 * Test der OpenAPI-Generierung (P07): Grundgerüst, Core-Endpunkte und
 * Modul-Routen inkl. Pfad-Parameter.
 */
class OpenApiGeneratorTest extends TestCase
{
    private function stubRegistry(): ApiRouteRegistry
    {
        return new class extends ApiRouteRegistry {
            public function all(): array
            {
                return [[
                    'module_key' => 'demo',
                    'isolation' => 'in_process',
                    'method' => 'GET',
                    'path' => '/things/{id}',
                    'class' => 'Demo\\Endpoint',
                    'summary' => 'Ein Ding lesen',
                    'scope' => 'demo:read',
                ]];
            }
        };
    }

    public function testGeneratesValidSkeletonWithCoreAndModulePaths(): void
    {
        $spec = (new OpenApiGenerator($this->stubRegistry()))->generate('https://host');

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertSame('https://host', $spec['servers'][0]['url']);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);

        // Core-Endpunkt vorhanden.
        $this->assertArrayHasKey('get', $spec['paths']['/api/v1/health']);

        // Modul-Endpunkt vorhanden, mit Pfad-Parameter.
        $op = $spec['paths']['/api/v1/m/demo/things/{id}']['get'];
        $this->assertSame('Ein Ding lesen', $op['summary']);
        $this->assertSame(['demo'], $op['tags']);
        $this->assertSame('id', $op['parameters'][0]['name']);
        $this->assertStringContainsString('demo:read', $op['description']);
    }
}
