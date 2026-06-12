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
    private WebRouteRegistry $webRoutes;

    public function __construct(?WebRouteRegistry $webRoutes = null)
    {
        $this->webRoutes = $webRoutes ?? new WebRouteRegistry();
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
