<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Sdk;

use App\Service\Module\ModuleManifest;
use App\Service\Sdk\ManifestLinter;
use Cake\TestSuite\TestCase;

/**
 * Test for the manifest `tables` exception section (Inc 9b): only module-global tables
 * need declaring (undeclared = tenant-scoped, must conform at install); the linter
 * validates the section shape and the manifest exposes the global exception list.
 */
class ManifestLinterTablesTest extends TestCase
{
    public function testValidTablesSectionPasses(): void
    {
        $result = (new ManifestLinter())->lint([
            'tables' => [
                ['table' => 'system_settings', 'scope' => 'global', 'reason' => 'module-wide config, no tenant dimension'],
                ['table' => 'tickets', 'scope' => 'tenant_scoped'],
            ],
        ]);
        $this->assertSame([], array_filter($result['errors'], static fn($e) => str_contains($e, 'tables')));
    }

    public function testInvalidTableNameAndScopeError(): void
    {
        $result = (new ManifestLinter())->lint([
            'tables' => [
                ['table' => 'Bad-Name', 'scope' => 'global', 'reason' => 'x'],
                ['table' => 'ok', 'scope' => 'nonsense'],
            ],
        ]);
        $errs = implode("\n", $result['errors']);
        $this->assertStringContainsString("tables [0]: 'table'", $errs);
        $this->assertStringContainsString("tables [1]: 'scope'", $errs);
    }

    public function testGlobalWithoutReasonWarns(): void
    {
        $result = (new ManifestLinter())->lint([
            'tables' => [['table' => 'lookup_codes', 'scope' => 'global']],
        ]);
        $this->assertNotEmpty(array_filter($result['warnings'], static fn($w) => str_contains($w, 'reason')));
    }

    public function testManifestExposesGlobalTables(): void
    {
        $manifest = new ModuleManifest([
            'tables' => [
                ['table' => 'system_settings', 'scope' => 'global', 'reason' => 'x'],
                ['table' => 'api_tokens', 'scope' => 'global', 'reason' => 'y'],
                ['table' => 'tickets', 'scope' => 'tenant_scoped'],
            ],
        ]);
        $this->assertSame(['system_settings', 'api_tokens'], $manifest->globalTables());
        $this->assertCount(3, $manifest->tables());
    }
}
