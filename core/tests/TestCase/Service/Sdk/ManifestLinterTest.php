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
        $this->assertTrue((bool)array_filter($r['errors'], fn($e) => str_contains($e, 'publisher')));
    }

    public function testInvalidIdAndApiRoute(): void
    {
        $m = $this->valid();
        $m['id'] = 'Bad-Id';
        $m['api_routes'] = [['method' => 'FETCH', 'path' => 'ping', 'class' => 'X']];
        $r = (new ManifestLinter())->lint($m);
        $this->assertTrue((bool)array_filter($r['errors'], fn($e) => str_contains($e, 'id ungültig')));
        $this->assertTrue((bool)array_filter($r['errors'], fn($e) => str_contains($e, 'method')));
        $this->assertTrue((bool)array_filter($r['errors'], fn($e) => str_contains($e, "mit '/' beginnen")));
    }

    public function testClassOutsideNamespaceIsWarning(): void
    {
        $m = $this->valid();
        $m['collectors_registered'] = [['contract' => 'core.collector.scheduled', 'class' => 'Other\\Task']];
        $r = (new ManifestLinter())->lint($m);
        $this->assertSame([], $r['errors']);
        $this->assertNotEmpty($r['warnings']);
    }

    /**
     * Integration-extension module / connector (ch. 23.5.2, E162): requires
     * integration_relations and, as a leaf node (ch. 23.5.5), must provide no
     * contracts.
     */
    public function testIntegrationExtensionRequiresRelationsAndProvidesNothing(): void
    {
        $base = $this->valid();
        $base['type'] = 'integration';

        // (a) missing integration_relations -> error.
        $errs = (new ManifestLinter())->lint($base)['errors'];
        $this->assertTrue((bool)array_filter($errs, fn($e) => str_contains($e, 'integration_relations')));

        // (b) leaf node: providing a contract -> error.
        $m = $base;
        $m['integration_relations'] = [['module' => 'ticketing'], ['module' => 'knowledgebase']];
        $m['contracts_provided'] = [
            ['name' => 'x.svc', 'type' => 'service', 'version' => '1.0.0', 'error_behavior' => 'reject'],
        ];
        $errs = (new ManifestLinter())->lint($m)['errors'];
        $this->assertTrue((bool)array_filter($errs, fn($e) => str_contains($e, 'Blattknoten')));

        // (b2) leaf node: declaring a web_route (entry point) -> error.
        $mw = $base;
        $mw['integration_relations'] = [['module' => 'ticketing']];
        $mw['web_routes'] = [['path' => '/x', 'class' => 'X', 'template' => 'x', 'auth' => 'user']];
        $errsW = (new ManifestLinter())->lint($mw)['errors'];
        $this->assertTrue((bool)array_filter($errsW, fn($e) => str_contains($e, 'Consumer-only')));

        // (c) valid integration -> no integration/leaf errors (and no type warning).
        $ok = $base;
        $ok['integration_relations'] = [['module' => 'ticketing'], ['module' => 'knowledgebase']];
        $r = (new ManifestLinter())->lint($ok);
        $this->assertSame([], array_values(array_filter(
            $r['errors'],
            fn($e) => str_contains($e, 'integration_relations') || str_contains($e, 'Blattknoten'),
        )));
        $this->assertSame([], array_values(array_filter($r['warnings'], fn($e) => str_contains($e, 'type unüblich'))));
    }

    /**
     * An admin web page (declares an `area`) must be authenticated: pairing `area`
     * with `auth='guest'` would bypass login, the per-tenant module-enablement gate
     * AND the area check in the web-mount dispatcher, yet still render in the admin
     * shell (Increment 5.1, adversarial-review HIGH). The linter rejects it; a
     * `user` (or default-auth) admin page is fine.
     */
    public function testGuestAdminWebRouteIsRejected(): void
    {
        $m = $this->valid();
        $m['web_routes'] = [
            ['path' => '/admin', 'class' => 'Acme\\Demo\\AdminPage', 'template' => 'admin', 'area' => 'demo_admin', 'auth' => 'guest'],
        ];
        $errs = (new ManifestLinter())->lint($m)['errors'];
        $this->assertTrue(
            (bool)array_filter($errs, fn($e) => str_contains($e, "'area' erfordert auth='user'")),
            'a guest admin web_route must be rejected',
        );

        // The same admin page with the default auth (= user) is accepted.
        $ok = $this->valid();
        $ok['web_routes'] = [
            ['path' => '/admin', 'class' => 'Acme\\Demo\\AdminPage', 'template' => 'admin', 'area' => 'demo_admin'],
        ];
        $errs = (new ManifestLinter())->lint($ok)['errors'];
        $this->assertSame([], array_values(array_filter($errs, fn($e) => str_contains($e, "erfordert auth='user'"))));
    }

    /**
     * Per-tenant config schema (Increment 5 Phase 3): valid fields pass; a bad key,
     * an unknown type, and a select without options are each flagged.
     */
    public function testConfigSchemaValidation(): void
    {
        $ok = $this->valid();
        $ok['config_schema'] = [
            ['key' => 'retries', 'label' => 'm.retries', 'type' => 'int'],
            ['key' => 'mode', 'label' => 'm.mode', 'type' => 'select', 'options' => ['a' => 'A']],
        ];
        $this->assertSame([], array_values(array_filter(
            (new ManifestLinter())->lint($ok)['errors'],
            fn($e) => str_contains($e, 'config_schema'),
        )), 'a valid config_schema produces no errors');

        $bad = $this->valid();
        $bad['config_schema'] = [
            ['key' => 'Bad-Key', 'label' => 'x', 'type' => 'string'],
            ['key' => 'k2', 'label' => 'y', 'type' => 'frobnicate'],
            ['key' => 'k3', 'label' => 'z', 'type' => 'select'],
        ];
        $errs = (new ManifestLinter())->lint($bad)['errors'];
        $this->assertTrue((bool)array_filter($errs, fn($e) => str_contains($e, "config_schema [0]: 'key'")));
        $this->assertTrue((bool)array_filter($errs, fn($e) => str_contains($e, "config_schema [1]: 'type'")));
        $this->assertTrue((bool)array_filter($errs, fn($e) => str_contains($e, "config_schema [2]: 'select'")));
    }

    /**
     * Enhancing-not-gating (ch. 26.19, Decision 184): a provided resolver/service
     * contract without error_behavior is an error; collector/event are exempt
     * (additive by nature); a declared error_behavior clears the error.
     */
    public function testProvidedResolverServiceContractRequiresErrorBehavior(): void
    {
        $m = $this->valid();
        $m['contracts_provided'] = [
            ['name' => 'demo_modul.resolver.x', 'type' => 'resolver', 'version' => '1.0.0'],
            ['name' => 'demo_modul.service.y', 'type' => 'service', 'version' => '1.0.0', 'error_behavior' => 'Default greift.'],
            ['name' => 'demo_modul.event.z', 'type' => 'event', 'version' => '1.0.0'],
        ];
        $errs = (new ManifestLinter())->lint($m)['errors'];

        $behaviorErrors = array_values(array_filter($errs, fn($e) => str_contains($e, 'error_behavior')));
        // Only the resolver (index 0) is flagged: the service declares it, the event is exempt.
        $this->assertCount(1, $behaviorErrors);
        $this->assertStringContainsString('contracts_provided [0]', $behaviorErrors[0]);
    }
}
