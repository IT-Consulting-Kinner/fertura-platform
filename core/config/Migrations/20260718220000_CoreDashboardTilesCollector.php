<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Core collector contract for admin-dashboard tiles (Paket 4). A module docks a
 * dashboard tile provider ({@see \App\Service\Dashboard\DashboardTileProviderInterface})
 * onto this collector; the Core renders each enabled module's tiles in its own
 * accordion group below the operator/core group.
 *
 * Tenant-gated like every collector (operator/tenant authz §5): only modules
 * enabled for the viewing tenant contribute, and the module-free operator tenant
 * (multi_org) sees no module tiles.
 */
class CoreDashboardTilesCollector extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            INSERT INTO core.contracts
                (owner_module_key, name, contract_type, version, multi_use, active, description)
            VALUES
                ('core', 'core.collector.dashboard_tiles', 'collector', '1.0.0', true, true,
                 'Admin-Dashboard-Kacheln der Module (Paket 4). Implementierungen liefern App\Service\Dashboard\DashboardTileProviderInterface.')
            ON CONFLICT (name) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        $this->execute("DELETE FROM core.contracts WHERE name = 'core.collector.dashboard_tiles'");
    }
}
