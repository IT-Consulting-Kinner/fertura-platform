<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Module;

use App\Service\Module\LifecycleException;
use App\Service\Module\ModuleLifecycle;
use App\Service\Module\ModuleManifest;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use ReflectionMethod;

/**
 * Focused test for the Inc 9c tenant-conformance gate's per-table check
 * ({@see ModuleLifecycle::tenantTableViolation()}): it proves that EACH way a module
 * table can leak across tenants or fall out of backup/consumption scope is caught —
 * a missing/nullable/mistyped tenant_id, missing ENABLE/FORCE RLS, a permissive
 * policy, and a non-tenant-scoped UNIQUE — while a conformant table passes.
 */
class ModuleTenantGateTest extends TestCase
{
    private string $schema = 'mod_zz9c';
    private ReflectionMethod $check;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn()->execute("DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
        $this->conn()->execute("CREATE SCHEMA {$this->schema}");
        $this->check = new ReflectionMethod(ModuleLifecycle::class, 'tenantTableViolation');
        $this->check->setAccessible(true);
    }

    protected function tearDown(): void
    {
        $this->conn()->execute("DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
        parent::tearDown();
    }

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $c */
        $c = ConnectionManager::get('default');

        return $c;
    }

    private function violation(string $table): ?string
    {
        return $this->check->invoke(new ModuleLifecycle(), $this->schema, $table);
    }

    private function exec(string $sql): void
    {
        $this->conn()->execute($sql);
    }

    public function testHelperBuiltTableIsConformant(): void
    {
        $this->conn()->execute(
            "SELECT core.create_tenant_table(:s, 'good', 'id uuid primary key, label text')",
            ['s' => $this->schema],
        );
        $this->assertNull($this->violation('good'), 'a core.create_tenant_table() table is conformant');
    }

    public function testMissingTenantIdIsCaught(): void
    {
        $this->exec("CREATE TABLE {$this->schema}.no_tenant (id uuid PRIMARY KEY)");
        $this->assertStringContainsString('keine tenant_id', (string)$this->violation('no_tenant'));
    }

    public function testNullableTenantIdIsCaught(): void
    {
        $this->exec("CREATE TABLE {$this->schema}.nullable_t (id uuid PRIMARY KEY, tenant_id uuid)");
        $this->assertStringContainsString('nullable', (string)$this->violation('nullable_t'));
    }

    public function testMissingRlsIsCaught(): void
    {
        $this->exec(
            "CREATE TABLE {$this->schema}.no_rls "
            . '(id uuid PRIMARY KEY, tenant_id uuid NOT NULL DEFAULT core.current_tenant())',
        );
        $this->assertStringContainsString('RLS nicht aktiviert', (string)$this->violation('no_rls'));
    }

    public function testMissingForceIsCaught(): void
    {
        $this->exec(
            "CREATE TABLE {$this->schema}.no_force "
            . '(id uuid PRIMARY KEY, tenant_id uuid NOT NULL DEFAULT core.current_tenant())',
        );
        $this->exec("ALTER TABLE {$this->schema}.no_force ENABLE ROW LEVEL SECURITY");
        $this->exec(
            "CREATE POLICY p ON {$this->schema}.no_force "
            . 'USING (core.rls_bypass() OR tenant_id = core.current_tenant()) '
            . 'WITH CHECK (core.rls_bypass() OR tenant_id = core.current_tenant())',
        );
        $this->assertStringContainsString('nicht erzwungen', (string)$this->violation('no_force'));
    }

    public function testPermissivePolicyIsCaught(): void
    {
        $this->exec(
            "CREATE TABLE {$this->schema}.permissive "
            . '(id uuid PRIMARY KEY, tenant_id uuid NOT NULL DEFAULT core.current_tenant())',
        );
        $this->exec("ALTER TABLE {$this->schema}.permissive ENABLE ROW LEVEL SECURITY");
        $this->exec("ALTER TABLE {$this->schema}.permissive FORCE ROW LEVEL SECURITY");
        // A policy that does NOT bind tenant_id to current_tenant() — the leak class.
        $this->exec("CREATE POLICY p ON {$this->schema}.permissive USING (true) WITH CHECK (true)");
        $this->assertStringContainsString('USING', (string)$this->violation('permissive'));
    }

    public function testGlobalUniqueIsCaught(): void
    {
        $this->conn()->execute(
            "SELECT core.create_tenant_table(:s, 'bad_unique', 'id uuid primary key, label text')",
            ['s' => $this->schema],
        );
        // A unique NOT scoped by tenant_id = a cross-tenant existence oracle.
        $this->exec("CREATE UNIQUE INDEX uq_bad ON {$this->schema}.bad_unique (label)");
        $this->assertStringContainsString('tenant_id nicht', (string)$this->violation('bad_unique'));
    }

    public function testTenantScopedUniquePasses(): void
    {
        $this->conn()->execute(
            "SELECT core.create_tenant_table(:s, 'good_unique', 'id uuid primary key, label text')",
            ['s' => $this->schema],
        );
        $this->conn()->execute(
            "SELECT core.add_tenant_unique(:s, 'good_unique', 'uq_good', 'label')",
            ['s' => $this->schema],
        );
        $this->assertNull($this->violation('good_unique'), 'a tenant-first unique is fine');
    }

    public function testGateChecksTablesEvenWithoutIsScoped(): void
    {
        // Inc 9e: a module with a GLOBAL capability (is_scoped:false) but tenant-bearing
        // tables (the ticketing<->KB connector case) must still be checked — keying the
        // gate on is_scoped would silently skip it.
        $this->exec("CREATE TABLE {$this->schema}.leaky (id uuid PRIMARY KEY, name text)");
        $assert = new ReflectionMethod(ModuleLifecycle::class, 'assertTenantTablesConform');
        $assert->setAccessible(true);
        $globalCap = new ModuleManifest(['permissions' => [['name' => 'x.cap', 'is_scoped' => false]]]);

        try {
            $assert->invoke(new ModuleLifecycle(), $this->schema, $globalCap);
            $this->fail('a non-conformant table must be caught even without is_scoped');
        } catch (LifecycleException $e) {
            $this->assertStringContainsString('leaky', $e->getMessage());
        }

        // Declaring the table module-global lets the gate pass (no throw).
        $declared = new ModuleManifest([
            'permissions' => [['name' => 'x.cap', 'is_scoped' => false]],
            'tables' => [['table' => 'leaky', 'scope' => 'global', 'reason' => 'test']],
        ]);
        $assert->invoke(new ModuleLifecycle(), $this->schema, $declared);
        $this->addToAssertionCount(1);
    }
}
