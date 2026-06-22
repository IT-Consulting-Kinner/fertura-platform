<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Per-tenant module enablement (operator/tenant authz design §5, Increment 5.1,
 * Decision 185).
 *
 * Until now a module had a single GLOBAL lifecycle state (`core.modules.status`):
 * once the operator activated it, it was active platform-wide and reachable by
 * every tenant. This table introduces the second axis the SaaS model needs — a
 * STRICT OPT-IN, fail-closed per-tenant grant:
 *
 *   - The operator still owns the lifecycle (install / activate / deactivate) =
 *     platform-wide availability of a module.
 *   - A tenant additionally only *uses* a module when an explicit row here marks
 *     it `enabled` for that tenant (chosen model: strict opt-in / fail-closed —
 *     no row means no access, see the dispatch/nav gates in `ModuleWebController`
 *     and `WebRouteRegistry::adminNav`).
 *
 * Backward compatibility: existing installs (notably Single-Org, where the
 * default tenant both *is* the operator and uses the modules) would otherwise lose
 * their modules the moment the gate goes live. The backfill below grants every
 * currently-active module to every existing tenant, so nothing disappears on
 * upgrade. Modules activated AFTER this migration are opt-in per tenant (the
 * tenant-facing enable surface, Increment 5.3, and `TenantService`/`ModuleLifecycle`
 * provisioning hooks).
 *
 * Tenancy: RLS tenant-scoped with the standard E165+ core-RLS policy
 * (`core.rls_bypass() OR tenant_id = core.current_tenant()`). `tenant_id` is NOT
 * NULL here (unlike the feature tables that keep it nullable for in-context
 * capture) because a grant row is meaningless without its tenant — every writer
 * (the tenant-admin GUI, the provisioning hooks, the backfill) supplies it
 * explicitly. The FKs cascade so deleting a tenant or uninstalling a module
 * removes its grants automatically.
 *
 * `config jsonb` is the forward-looking per-tenant module configuration payload
 * (design §5, the per-tenant config contract — Phase 3); unused by the 5.1 gate
 * but part of the table's defined shape so configuration does not need a later
 * schema change.
 */
class CoreTenantModules extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.tenant_modules (
                id         uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                tenant_id  uuid        NOT NULL DEFAULT core.current_tenant(),
                module_key text        NOT NULL,
                enabled    boolean     NOT NULL DEFAULT true,
                config     jsonb       NOT NULL DEFAULT '{}',
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_tenant_modules UNIQUE (tenant_id, module_key),
                CONSTRAINT fk_tenant_modules_tenant
                    FOREIGN KEY (tenant_id) REFERENCES core.tenants(id) ON DELETE CASCADE,
                CONSTRAINT fk_tenant_modules_module
                    FOREIGN KEY (module_key) REFERENCES core.modules(module_key) ON DELETE CASCADE
            )
            SQL);
        $this->execute(
            'CREATE TRIGGER trg_tenant_modules_set_updated_at BEFORE UPDATE ON core.tenant_modules '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()',
        );

        // Backward compatibility: grant every currently-active module to every
        // existing tenant, so the gate does not strip modules on upgrade. Runs as
        // the migration owner (RLS-bypassing), hence the cross-tenant insert.
        $this->execute(<<<'SQL'
            INSERT INTO core.tenant_modules (tenant_id, module_key, enabled)
            SELECT t.id, m.module_key, true
            FROM core.tenants t
            CROSS JOIN core.modules m
            WHERE m.status = 'active'
            ON CONFLICT (tenant_id, module_key) DO NOTHING
            SQL);

        $this->execute('ALTER TABLE core.tenant_modules ENABLE ROW LEVEL SECURITY');
        $this->execute(
            'CREATE POLICY p_tenant_modules_tenant ON core.tenant_modules '
            . 'USING (core.rls_bypass() OR tenant_id = core.current_tenant()) '
            . 'WITH CHECK (core.rls_bypass() OR tenant_id = core.current_tenant())',
        );
    }

    public function down(): void
    {
        $this->execute('DROP POLICY IF EXISTS p_tenant_modules_tenant ON core.tenant_modules');
        $this->execute('ALTER TABLE core.tenant_modules DISABLE ROW LEVEL SECURITY');
        $this->execute('DROP TABLE IF EXISTS core.tenant_modules');
    }
}
