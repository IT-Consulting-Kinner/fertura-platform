<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\System;

use App\Service\System\ModuleInstallHandler;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * The module-install handler's post-action VERIFY (Phase 6): asserts the module
 * landed (a modules row in an installed/active state + its mod_<key> schema). The
 * execute/rollback paths run the real DDL-heavy lifecycle and are exercised through
 * the module-install infrastructure; here we cover the verifier in isolation.
 */
class ModuleInstallHandlerTest extends TestCase
{
    private const KEY = 'zzhandlertest';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute('DELETE FROM modules WHERE module_key = :k', ['k' => self::KEY]);
        $conn->execute('DROP SCHEMA IF EXISTS mod_' . self::KEY . ' CASCADE');
    }

    private function seedModule(): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO modules (module_key, name, version, type, core_compatibility, manifest) '
            . "VALUES (:k, 'ZZ Handler Test', '1.0.0', 'main', '>=1.0.0', '{}'::jsonb)",
            ['k' => self::KEY],
        );
    }

    public function testVerifyFailsWithoutModuleKey(): void
    {
        $this->assertFalse((new ModuleInstallHandler())->verify([])['ok']);
    }

    public function testVerifyFailsWhenModuleRowMissing(): void
    {
        $this->assertFalse((new ModuleInstallHandler())->verify(['module_key' => self::KEY])['ok']);
    }

    public function testVerifyFailsWhenSchemaMissing(): void
    {
        $this->seedModule(); // row present, but no mod_<key> schema
        $verdict = (new ModuleInstallHandler())->verify(['module_key' => self::KEY]);
        $this->assertFalse($verdict['ok']);
        $this->assertStringContainsString('Schema', (string)$verdict['reason']);
    }

    public function testVerifyOkWhenModuleAndSchemaPresent(): void
    {
        $this->seedModule();
        ConnectionManager::get('default')->execute('CREATE SCHEMA mod_' . self::KEY);

        $this->assertTrue((new ModuleInstallHandler())->verify(['module_key' => self::KEY])['ok']);
    }
}
