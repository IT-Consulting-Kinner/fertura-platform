<?php
declare(strict_types=1);

namespace App\Test\TestCase\Database;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Test for the Core per-tenant table helpers (Inc 9a): a table built via
 * core.create_tenant_table() has the exact conformant shape the Inc 9c install gate
 * requires (tenant_id NOT NULL DEFAULT current_tenant, RLS enabled+forced, canonical
 * policy), and core.add_tenant_unique() prepends tenant_id to a unique constraint.
 */
class TenantTableHelpersTest extends TestCase
{
    private string $schema = 'mod_zz9a';

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn()->execute("DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
        $this->conn()->execute("CREATE SCHEMA {$this->schema}");
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

    public function testCreateTenantTableBuildsConformantShape(): void
    {
        $this->conn()->execute(
            "SELECT core.create_tenant_table(:s, 'widget', 'id uuid primary key, label text')",
            ['s' => $this->schema],
        );

        // tenant_id: uuid, NOT NULL, DEFAULT core.current_tenant()
        $col = $this->conn()->execute(
            'SELECT udt_name, is_nullable, column_default FROM information_schema.columns '
            . "WHERE table_schema = :s AND table_name = 'widget' AND column_name = 'tenant_id'",
            ['s' => $this->schema],
        )->fetch('assoc');
        $this->assertNotFalse($col, 'tenant_id column exists');
        $this->assertSame('uuid', $col['udt_name']);
        $this->assertSame('NO', $col['is_nullable'], 'tenant_id is NOT NULL');
        $this->assertStringContainsString('current_tenant', (string)$col['column_default']);

        // RLS enabled AND forced
        $rls = $this->conn()->execute(
            'SELECT c.relrowsecurity, c.relforcerowsecurity FROM pg_class c '
            . 'JOIN pg_namespace n ON n.oid = c.relnamespace '
            . "WHERE n.nspname = :s AND c.relname = 'widget'",
            ['s' => $this->schema],
        )->fetch('assoc');
        $this->assertTrue($rls['relrowsecurity'] === true || $rls['relrowsecurity'] === 't', 'RLS enabled');
        $this->assertTrue($rls['relforcerowsecurity'] === true || $rls['relforcerowsecurity'] === 't', 'RLS forced');

        // A fail-closed policy referencing tenant_id + current_tenant in USING and WITH CHECK
        $pol = $this->conn()->execute(
            'SELECT qual, with_check FROM pg_policies WHERE schemaname = :s AND tablename = :t',
            ['s' => $this->schema, 't' => 'widget'],
        )->fetch('assoc');
        $this->assertNotFalse($pol, 'a policy exists');
        foreach (['qual' => $pol['qual'], 'with_check' => $pol['with_check']] as $label => $expr) {
            $this->assertStringContainsString('tenant_id', (string)$expr, "$label references tenant_id");
            $this->assertStringContainsString('current_tenant', (string)$expr, "$label references current_tenant");
        }
    }

    public function testAddTenantUniquePrependsTenantId(): void
    {
        $this->conn()->execute(
            "SELECT core.create_tenant_table(:s, 'widget', 'id uuid primary key, label text')",
            ['s' => $this->schema],
        );
        $this->conn()->execute(
            "SELECT core.add_tenant_unique(:s, 'widget', 'uq_widget_label', 'label')",
            ['s' => $this->schema],
        );

        $def = $this->conn()->execute(
            'SELECT pg_get_constraintdef(con.oid) AS def FROM pg_constraint con '
            . 'JOIN pg_class c ON c.oid = con.conrelid JOIN pg_namespace n ON n.oid = c.relnamespace '
            . "WHERE n.nspname = :s AND c.relname = 'widget' AND con.conname = 'uq_widget_label'",
            ['s' => $this->schema],
        )->fetch('assoc');
        $this->assertNotFalse($def, 'the unique constraint exists');
        $this->assertStringContainsString('UNIQUE (tenant_id, label)', (string)$def['def']);
    }
}
