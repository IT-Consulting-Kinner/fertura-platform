<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Module;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * The `core.modules` type constraint accepts the integration-extension /
 * connector type (E164, completes E162) — so a real connector install no longer
 * fails at the INSERT.
 */
class ModuleTypeConstraintTest extends TestCase
{
    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $c */
        $c = ConnectionManager::get('default');

        return $c;
    }

    protected function tearDown(): void
    {
        $this->conn()->execute("DELETE FROM modules WHERE module_key LIKE 'zzint_%'");
        parent::tearDown();
    }

    public function testModulesTableAcceptsIntegrationType(): void
    {
        $id = $this->conn()->execute(
            'INSERT INTO modules (module_key, name, version, type, core_compatibility, status, manifest) '
            . "VALUES ('zzint_ok', 'ZZ Integration', '1.0.0', 'integration', '>=1.0.0', 'installed_inactive', '{}'::jsonb) "
            . 'RETURNING id',
        )->fetch('assoc')['id'];

        $this->assertNotEmpty($id);
    }
}
