<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Tenant-facing admin area `consumption` (Inc 7d): the surface where a TENANT admin
 * sees what their own tenant consumes — enabled functions (modules) + storage
 * (backup archives, per-tenant file store, approximate DB footprint). Gates
 * {@see \App\Controller\Admin\ConsumptionController}.
 *
 * Tenant-scoped (see {@see \App\Controller\Admin\AdminController::TENANT_AREAS}), so
 * holding it never crosses the operator boundary; the operator's per-tenant billing
 * view is a separate operator-scoped area (Inc 7e). Flat area key to match the
 * existing convention (tenant_modules, tenant_backup, …).
 */
class CoreConsumptionArea extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            INSERT INTO core.admin_areas (area_key, label, sort_order)
            VALUES ('consumption', 'Verbrauch', 35)
            ON CONFLICT (area_key) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        // FK RESTRICT: clear grants before the area itself.
        $this->execute("DELETE FROM core.user_admin_areas WHERE admin_area_key = 'consumption'");
        $this->execute("DELETE FROM core.admin_areas WHERE area_key = 'consumption'");
    }
}
