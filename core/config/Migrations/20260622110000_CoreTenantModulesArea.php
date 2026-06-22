<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Tenant-facing admin area `tenant_modules` (operator/tenant authz §4/§5,
 * Increment 5.3): the surface where a TENANT admin enables/disables the installed
 * modules for their own tenant — the consumer side of {@see \App\Service\Module\TenantModuleService}.
 *
 * Distinct from the operator-only `module_lifecycle` area (install/activate/remove
 * platform-wide): this one is tenant-scoped (see
 * {@see \App\Controller\Admin\AdminController::TENANT_AREAS}), so holding it does
 * NOT cross the operator boundary. Gates {@see \App\Controller\Admin\TenantModulesController}.
 * Flat area key to match the existing convention (core_config, system_maintenance, …).
 */
class CoreTenantModulesArea extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            INSERT INTO core.admin_areas (area_key, label, sort_order)
            VALUES ('tenant_modules', 'Mandanten-Module', 25)
            ON CONFLICT (area_key) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        // FK RESTRICT: clear grants before the area itself.
        $this->execute("DELETE FROM core.user_admin_areas WHERE admin_area_key = 'tenant_modules'");
        $this->execute("DELETE FROM core.admin_areas WHERE area_key = 'tenant_modules'");
    }
}
