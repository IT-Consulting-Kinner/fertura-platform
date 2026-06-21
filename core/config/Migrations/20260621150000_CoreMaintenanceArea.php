<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Maintenance mode — Phase 3: the dedicated admin area `system_maintenance`
 * (docs/maintenance-mode-design.md §6 decision #6 — a separate right, not bundled
 * with general admin access). Gates {@see \App\Controller\Admin\MaintenanceController}
 * via {@see \App\Controller\Admin\AdminController::$requiredArea}. Flat area key to
 * match the existing convention (core_config, localization, …).
 */
class CoreMaintenanceArea extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            INSERT INTO core.admin_areas (area_key, label, sort_order)
            VALUES ('system_maintenance', 'Wartung', 80)
            ON CONFLICT (area_key) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        // FK RESTRICT: clear grants before the area itself.
        $this->execute("DELETE FROM core.user_admin_areas WHERE admin_area_key = 'system_maintenance'");
        $this->execute("DELETE FROM core.admin_areas WHERE area_key = 'system_maintenance'");
    }
}
