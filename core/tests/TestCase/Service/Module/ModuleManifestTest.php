<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Module;

use App\Service\Module\ModuleManifest;
use Cake\TestSuite\TestCase;

/**
 * Activation-time manifest validation (E162): the integration-extension /
 * connector module type (ch. 23.5.2) — bridges N main modules via
 * integration_relations, anchors to none (no extends_main_module), and is a leaf
 * node that provides no contracts (ch. 23.5.5).
 */
class ModuleManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private function base(): array
    {
        return [
            'id' => 'demo',
            'name' => 'Demo',
            'version' => '1.0.0',
            'type' => 'main',
            'edition' => 'free',
            'description' => 'x',
            'core_compatibility' => '>=1.0.0 <2.0.0',
            'publisher' => 'Acme',
            'php_namespace' => 'Acme\\Demo',
        ];
    }

    public function testIntegrationTypeValidWithRelationsAndNoProvidedContracts(): void
    {
        $m = $this->base();
        $m['type'] = 'integration';
        $m['integration_relations'] = [['module' => 'ticketing'], ['module' => 'knowledgebase']];

        $errors = (new ModuleManifest($m))->validate('1.5.0');

        // No integration/leaf error, and crucially NO extends_main_module requirement.
        $this->assertSame([], array_values(array_filter(
            $errors,
            fn(string $e): bool => str_contains($e, 'integration_relations')
                || str_contains($e, 'Blattknoten')
                || str_contains($e, 'extends_main_module')
                || str_contains($e, 'Ungültiger Typ'),
        )));
    }

    public function testIntegrationWithoutRelationsFails(): void
    {
        $m = $this->base();
        $m['type'] = 'integration';

        $errors = (new ModuleManifest($m))->validate('1.5.0');

        $this->assertTrue((bool)array_filter($errors, fn(string $e): bool => str_contains($e, 'integration_relations')));
    }

    public function testIntegrationProvidingContractRejectedAsLeafNode(): void
    {
        $m = $this->base();
        $m['type'] = 'integration';
        $m['integration_relations'] = [['module' => 'ticketing']];
        $m['contracts_provided'] = [
            ['name' => 'x.svc', 'type' => 'service', 'version' => '1.0.0', 'error_behavior' => 'reject'],
        ];

        $errors = (new ModuleManifest($m))->validate('1.5.0');

        $this->assertTrue((bool)array_filter($errors, fn(string $e): bool => str_contains($e, 'Blattknoten')));
    }

    public function testUnknownTypeRejected(): void
    {
        $m = $this->base();
        $m['type'] = 'bogus';

        $errors = (new ModuleManifest($m))->validate('1.5.0');

        $this->assertTrue((bool)array_filter($errors, fn(string $e): bool => str_contains($e, 'Ungültiger Typ')));
    }
}
