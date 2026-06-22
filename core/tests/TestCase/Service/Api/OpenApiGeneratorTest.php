<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Api;

use App\Service\Api\ApiRouteRegistry;
use App\Service\Api\OpenApiGenerator;
use App\Service\Module\TenantModuleService;
use Cake\TestSuite\TestCase;

/**
 * Tests OpenAPI generation (P07): the skeleton, core endpoints, and module
 * routes including path parameters; plus the per-tenant visibility (operator/
 * tenant authz §5) — a `user` module route appears only when the module is
 * enabled for the tenant, a `public` route always appears.
 */
class OpenApiGeneratorTest extends TestCase
{
    private function stubRegistry(): ApiRouteRegistry
    {
        return new class extends ApiRouteRegistry {
            public function all(): array
            {
                return [
                    [
                        'module_key' => 'demo', 'isolation' => 'in_process', 'method' => 'GET',
                        'path' => '/things/{id}', 'class' => 'Demo\\Endpoint',
                        'summary' => 'Ein Ding lesen', 'scope' => 'demo:read', 'auth' => 'user',
                    ],
                    [
                        'module_key' => 'demo', 'isolation' => 'in_process', 'method' => 'GET',
                        'path' => '/public-status', 'class' => 'Demo\\Status',
                        'summary' => 'Oeffentlicher Status', 'scope' => null, 'auth' => 'public',
                    ],
                ];
            }
        };
    }

    /** @param list<string> $enabled */
    private function stubTenantModules(array $enabled): TenantModuleService
    {
        return new class ($enabled) extends TenantModuleService {
            /** @param list<string> $enabled */
            public function __construct(private array $enabled)
            {
            }

            /** @return list<string> */
            public function enabledKeys(): array
            {
                return $this->enabled;
            }
        };
    }

    public function testGeneratesValidSkeletonWithCoreAndModulePaths(): void
    {
        $spec = (new OpenApiGenerator($this->stubRegistry(), $this->stubTenantModules(['demo'])))
            ->generate('https://host');

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertSame('https://host', $spec['servers'][0]['url']);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);

        // Core endpoint present.
        $this->assertArrayHasKey('get', $spec['paths']['/api/v1/health']);

        // Module endpoint present (module enabled for the tenant), with path parameter.
        $op = $spec['paths']['/api/v1/m/demo/things/{id}']['get'];
        $this->assertSame('Ein Ding lesen', $op['summary']);
        $this->assertSame(['demo'], $op['tags']);
        $this->assertSame('id', $op['parameters'][0]['name']);
        $this->assertStringContainsString('demo:read', $op['description']);
    }

    public function testDisabledModuleHidesUserRouteButKeepsPublicAndCore(): void
    {
        // Module 'demo' NOT enabled for the tenant.
        $spec = (new OpenApiGenerator($this->stubRegistry(), $this->stubTenantModules([])))
            ->generate('https://host');

        // A `user` route of a non-enabled module is not advertised (the dispatcher 404s it).
        $this->assertArrayNotHasKey('/api/v1/m/demo/things/{id}', $spec['paths']);
        // A `public` route always appears (own auth, no tenant scope).
        $this->assertArrayHasKey('/api/v1/m/demo/public-status', $spec['paths']);
        // Core endpoints are unaffected.
        $this->assertArrayHasKey('/api/v1/health', $spec['paths']);
    }
}
