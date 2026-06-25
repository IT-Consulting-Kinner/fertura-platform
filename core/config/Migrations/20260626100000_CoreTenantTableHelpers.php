<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Core SQL helpers that codify the canonical per-tenant table shape (Inc 9a), so a
 * module migration gets a tenant-isolated, backup-discoverable table BY CONSTRUCTION
 * instead of hand-writing the column + RLS + policy + tenant-scoped unique each time.
 *
 * The shape mirrors the established pattern (CoreAuditLogTenant / the modules'
 * `*_tenancy` migrations): `tenant_id uuid NOT NULL DEFAULT core.current_tenant()`,
 * RLS ENABLED + FORCED (owner is bound too), and a fail-closed policy
 * `core.rls_bypass() OR tenant_id = core.current_tenant()` for USING and WITH CHECK.
 * It is exactly the shape the Inc 9c install gate enforces, so a helper-built table
 * passes the gate automatically.
 *
 * SECURITY INVOKER: the function runs with the caller's privileges and only does what
 * the caller could already do (it creates a table in a schema the caller has CREATE
 * on and owns the result), so it grants no extra power — hence EXECUTE to PUBLIC.
 * `%I` quotes identifiers; the column/columns text (`%s`) is the module's own trusted
 * migration DDL.
 */
class CoreTenantTableHelpers extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE OR REPLACE FUNCTION core.create_tenant_table(p_schema text, p_table text, p_columns text)
            RETURNS void LANGUAGE plpgsql AS $fn$
            DECLARE
                v_policy text := 'p_' || p_table || '_tenant';
            BEGIN
                EXECUTE format(
                    'CREATE TABLE %I.%I (%s, tenant_id uuid NOT NULL DEFAULT core.current_tenant())',
                    p_schema, p_table, p_columns
                );
                EXECUTE format('ALTER TABLE %I.%I ENABLE ROW LEVEL SECURITY', p_schema, p_table);
                EXECUTE format('ALTER TABLE %I.%I FORCE ROW LEVEL SECURITY', p_schema, p_table);
                EXECUTE format(
                    'CREATE POLICY %I ON %I.%I '
                    || 'USING (core.rls_bypass() OR tenant_id = core.current_tenant()) '
                    || 'WITH CHECK (core.rls_bypass() OR tenant_id = core.current_tenant())',
                    v_policy, p_schema, p_table
                );
            END;
            $fn$;
            SQL);

        $this->execute(<<<'SQL'
            CREATE OR REPLACE FUNCTION core.add_tenant_unique(p_schema text, p_table text, p_name text, p_cols text)
            RETURNS void LANGUAGE plpgsql AS $fn$
            BEGIN
                -- tenant_id is prepended so two tenants may reuse the same natural key
                -- (a global unique would be a cross-tenant existence oracle).
                EXECUTE format(
                    'ALTER TABLE %I.%I ADD CONSTRAINT %I UNIQUE (tenant_id, %s)',
                    p_schema, p_table, p_name, p_cols
                );
            END;
            $fn$;
            SQL);

        $this->execute('GRANT EXECUTE ON FUNCTION core.create_tenant_table(text, text, text) TO PUBLIC');
        $this->execute('GRANT EXECUTE ON FUNCTION core.add_tenant_unique(text, text, text, text) TO PUBLIC');
    }

    public function down(): void
    {
        $this->execute('DROP FUNCTION IF EXISTS core.create_tenant_table(text, text, text)');
        $this->execute('DROP FUNCTION IF EXISTS core.add_tenant_unique(text, text, text, text)');
    }
}
