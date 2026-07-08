<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Operator/module separation predicate (operator-tenant design §5b, Option B).
 *
 * Introduces the single source of truth for "may this tenant own module
 * functions?": in MULTI-ORG mode the operator/default tenant runs operator
 * functions ONLY, while modules live exclusively in the customer tenants; in
 * SINGLE-ORG mode the default tenant keeps its historic dual role (operator AND the
 * sole module user), so nothing is separated. The mode is the explicit, operator-
 * configurable app setting `core.tenancy.mode` (single_org | multi_org, default
 * single_org) — NOT the tenant count.
 *
 * The rule lives in ONE DB function so all four enablement-consuming SQL sites
 * (`TenantModuleService::isEnabled/enabledKeys/enabledModules` and
 * `ContractRegistry::gateByTenantModules`) share it and can never drift — the same
 * discipline `core.current_tenant()` already establishes for tenancy.
 *
 * `core.tenant_is_module_free(t)` is TRUE only when `t` is the default tenant AND
 * the mode is multi_org. It reads the GLOBAL (tenant_id NULL) setting row directly
 * from `core.settings` — which carries no RLS (see CoreSettings/CoreTenancy) and is
 * read tenant-neutrally, so it is evaluable from any tenant context and a stray
 * per-tenant override cannot flip the mode. Absent setting row -> single_org (the
 * catalog default) -> not module-free. A NULL argument yields NULL (SQL
 * `NULL = uuid`), which every caller treats as "not module-free" — so the existing
 * NULL/no-tenant behaviour of each gate is preserved exactly.
 */
class CoreTenantModuleFreeFunction extends BaseMigration
{
    /** The operator/default tenant (cf. CoreTenancy). */
    private const DEFAULT_TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function up(): void
    {
        $default = self::DEFAULT_TENANT_ID;
        // The setting value is stored as a jsonb scalar string ("multi_org"); `#>> '{}'`
        // extracts it as text. `coalesce(..., 'single_org')` makes an ABSENT setting row
        // resolve to the catalog default single_org (returns FALSE, not NULL) so an
        // operator-tenant module still reads as enabled in the default single-org mode.
        $this->execute(<<<SQL
            CREATE OR REPLACE FUNCTION core.tenant_is_module_free(t uuid)
            RETURNS boolean LANGUAGE sql STABLE AS \$func\$
                SELECT t = '$default'::uuid
                   AND coalesce(
                       (SELECT s.value #>> '{}'
                        FROM core.settings s
                        WHERE s.namespace = 'core'
                          AND s.config_key = 'tenancy.mode'
                          AND s.tenant_id IS NULL),
                       'single_org'
                   ) = 'multi_org'
            \$func\$
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP FUNCTION IF EXISTS core.tenant_is_module_free(uuid)');
    }
}
