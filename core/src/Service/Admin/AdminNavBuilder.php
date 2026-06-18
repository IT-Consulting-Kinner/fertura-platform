<?php
declare(strict_types=1);

namespace App\Service\Admin;

use App\Controller\Admin\AdminController;
use App\Service\Module\WebRouteRegistry;

/**
 * Builds the scoped admin navigation (ch. 27.3.1 / 23.16.3): the Core areas
 * ({@see AdminController::NAV}) **merged with** the admin pages contributed by
 * active modules (via their `web_routes` with an `area` + `nav` label), then
 * filtered to the areas the current user actually holds.
 *
 * Used by both {@see AdminController} (Core admin pages) and
 * {@see \App\Controller\ModuleWebController} (module admin pages) so the sidebar
 * is identical regardless of which controller renders the page. Visibility =
 * server-side authorization (the user must hold the area), not mere hiding.
 */
class AdminNavBuilder
{
    /**
     * Core areas under the "Administration" top menu, in display order. Note
     * `module_lifecycle` (module management) lives here, NOT under "Module" —
     * "Module" holds only the module-contributed setting areas (Ticketing, …).
     */
    public const ADMIN_ORDER = [
        'user_group_admin', 'module_lifecycle', 'core_config',
        'registry_contracts', 'localization', 'update_manager', 'marketplace_license',
    ];

    /** Always-available system pages (no admin-area gate; any admin may open). */
    public const SYSTEM = [
        'label' => 'admin.nav.system',
        'items' => [
            ['admin.nav.health', '/admin/health'],
            ['admin.nav.audit', '/admin/audit'],
            ['admin.nav.api_tokens', '/admin/tokens'],
        ],
    ];

    private WebRouteRegistry $webRoutes;

    public function __construct(?WebRouteRegistry $webRoutes = null)
    {
        $this->webRoutes = $webRoutes ?? new WebRouteRegistry();
    }

    /**
     * Splits the scoped navigation into the two top-menu dropdowns:
     *   - "module": the module-contributed setting areas (e.g. Ticketing, KB);
     *   - "administration": the Core admin areas (incl. module management) plus
     *     the always-available system group.
     *
     * @param list<string> $userAreaKeys areas the current user holds
     * @return array{module: array<string,array{label:string,items:list<array{0:string,1:string}>}>, administration: array<string,array{label:string,items:list<array{0:string,1:string}>}>}
     */
    public function menu(array $userAreaKeys): array
    {
        $nav = $this->build($userAreaKeys);
        $coreKeys = array_keys(AdminController::NAV);

        // "Module": every area NOT defined by the Core (module-contributed).
        $module = [];
        foreach ($nav as $key => $def) {
            if (!in_array($key, $coreKeys, true)) {
                $module[$key] = $def;
            }
        }

        // "Administration": Core areas in a fixed order; module_lifecycle gets the
        // clearer "Module management" label. The system group is always present.
        $administration = [];
        foreach (self::ADMIN_ORDER as $key) {
            if (!isset($nav[$key])) {
                continue;
            }
            $def = $nav[$key];
            if ($key === 'module_lifecycle') {
                $def['label'] = 'admin.nav.module_management';
            }
            $administration[$key] = $def;
        }
        $administration['system'] = self::SYSTEM;

        return ['module' => $module, 'administration' => $administration];
    }

    /** Which top-menu entry an area belongs to (for highlighting / back links). */
    public function areaTop(string $area): string
    {
        if ($area === 'system' || in_array($area, self::ADMIN_ORDER, true)) {
            return 'administration';
        }

        return 'module';
    }

    /**
     * @param list<string> $userAreaKeys areas the current user holds
     * @return array<string, array{label:string, items:list<array{0:string,1:string}>}>
     */
    public function build(array $userAreaKeys): array
    {
        $nav = AdminController::NAV;

        // Merge module-contributed admin pages into the matching area (or add a
        // new, module-defined area group).
        foreach ($this->webRoutes->adminNav() as $area => $group) {
            if (isset($nav[$area])) {
                $nav[$area]['items'] = array_merge($nav[$area]['items'], $group['items']);
            } else {
                $nav[$area] = $group;
            }
        }

        // Scope to the held areas (server-side authorization).
        $out = [];
        foreach ($nav as $key => $def) {
            if (in_array($key, $userAreaKeys, true)) {
                $out[$key] = $def;
            }
        }

        return $out;
    }
}
