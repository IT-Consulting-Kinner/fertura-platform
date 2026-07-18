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
        'system_maintenance',
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
     * Splits the held navigation into the two top-menu REALMS (Betreiber/Mandant,
     * operator-tenant design §6), grouped by area SCOPE:
     *   - operator realm: the operator-scoped Core areas (tenants, lifecycle, updates,
     *     system config/backup/trust, maintenance, …) + the system pages;
     *   - tenant realm: the tenant-scoped Core area (user/group mgmt) + the
     *     module-contributed setting areas (Ticketing, KB, …).
     *
     * The return KEYS stay `module`/`administration` so routes, highlighting and
     * breadcrumbs keep working unchanged; only the grouping and the visible LABELS
     * (Betreiber/Mandant, set in the layout) change. `administration` = operator
     * (Betreiber), `module` = tenant (Mandant). Grouping is by area SCOPE; which realms
     * a viewer SEES is then narrowed by their tenant (below).
     *
     * Which realms a viewer SEES is then narrowed by their tenant (operator-tenant
     * design §6/§5a, Option B): a CUSTOMER-tenant viewer (`$isOperatorTenant = false`)
     * never sees the operator (Betreiber) Core areas — they are unreachable anyway
     * (operator gate -> 403), so only the always-available system pages remain in that
     * realm. Single-org / operator viewers pass the default and keep BOTH realms — in
     * single-org the default tenant is BOTH operator and (only) module user. An operator
     * tenant in a multi-tenant install shows no module functions here regardless: its
     * module-contributed areas are already dropped in {@see build()} by the per-tenant
     * enablement gate (which reports the operator tenant as owning no modules).
     * (Renaming the keys/routes to betreiber/mandant is a later cosmetic cleanup.)
     *
     * @param list<string> $userAreaKeys areas the current user holds
     * @param bool $isOperatorTenant whether the viewer is in the operator/default tenant
     * @return array{module: array<string,array{label:string,items:list<array{0:string,1:string}>}>, administration: array<string,array{label:string,items:list<array{0:string,1:string}>}>}
     */
    public function menu(array $userAreaKeys, bool $isOperatorTenant = true): array
    {
        $nav = $this->build($userAreaKeys);

        // Operator realm: operator-scoped Core areas in a fixed order; module_lifecycle
        // gets the clearer "Module management" label. The system group rides along here.
        $operator = [];
        // Tenant realm: tenant-scoped Core areas (user/group mgmt) first, then the
        // module-contributed areas.
        $tenant = [];
        foreach (self::ADMIN_ORDER as $key) {
            if (!isset($nav[$key])) {
                continue;
            }
            $def = $nav[$key];
            if ($key === 'module_lifecycle') {
                $def['label'] = 'admin.nav.module_management';
            }
            if ($this->isOperatorArea($key)) {
                $operator[$key] = $def;
            } else {
                $tenant[$key] = $def;
            }
        }
        foreach ($nav as $key => $def) {
            if (!array_key_exists($key, AdminController::NAV)) { // module-contributed
                $tenant[$key] = $def;
            }
        }
        $operator['system'] = self::SYSTEM;

        // Narrow the visible realms to the viewer's tenant (see the docblock): a
        // customer-tenant viewer keeps only the always-available system pages in the
        // operator realm.
        if (!$isOperatorTenant) {
            $operator = ['system' => self::SYSTEM];
        }

        return ['module' => $tenant, 'administration' => $operator];
    }

    /** Which top-menu realm an area belongs to (for highlighting / back links). */
    public function areaTop(string $area): string
    {
        if ($area === 'system' || $this->isOperatorArea($area)) {
            return 'administration'; // operator realm (Betreiber)
        }

        return 'module'; // tenant realm (Mandant)
    }

    /**
     * Breadcrumb trail for a function page under an admin area: top menu → section
     * (the drill-down tile page, only when the area has more than one item) → the
     * current function, found by longest-prefix match over the area's nav items so
     * sub-pages (add/edit/view) still resolve to their leaf, which then links back
     * to the leaf's list page.
     *
     * Shared by {@see AdminController} (Core admin pages) and
     * {@see \App\Controller\ModuleWebController} (module admin pages) so both
     * render the identical trail (ch. 23.16.3). Module-contributed areas work
     * unchanged because {@see WebRouteRegistry::adminNav()} already resolves their
     * labels in the module's i18n domain and their item URLs to `/m/<key>/…`.
     *
     * @param list<string> $userAreaKeys areas the current user holds
     * @param string $requestPath current request path (for the leaf prefix match)
     * @return list<array{0:string,1:?string}> [label, url|null]; null = current
     */
    public function breadcrumb(array $userAreaKeys, string $area, string $requestPath): array
    {
        $top = $this->areaTop($area);
        $crumbs = [[$top === 'module' ? 'admin.nav.modules' : 'admin.nav.administration', '/admin/' . $top]];

        $def = $this->build($userAreaKeys)[$area] ?? null;
        if ($def === null) {
            return $crumbs;
        }
        $label = $area === 'module_lifecycle' ? 'admin.nav.module_management' : $def['label'];
        $items = $def['items'];

        if (count($items) === 1) {
            // No drill-down tile page exists: the group label IS the function.
            $crumbs[] = [$label, null];

            return $crumbs;
        }

        // Section tile page, then the current function — found by longest-prefix
        // match so sub-pages (add/edit/view) still resolve to their leaf, which
        // then links back to the leaf's list page.
        $crumbs[] = [$label, '/admin/section/' . $area];
        $best = null;
        $bestLen = -1;
        foreach ($items as $it) {
            $url = (string)$it[1];
            if (str_starts_with($requestPath, $url) && strlen($url) > $bestLen) {
                $best = $it;
                $bestLen = strlen($url);
            }
        }
        if ($best !== null) {
            $onLeaf = rtrim($requestPath, '/') === rtrim((string)$best[1], '/');
            $crumbs[] = [(string)$best[0], $onLeaf ? null : (string)$best[1]];
        }

        return $crumbs;
    }

    /**
     * Operator-scoped = a Core area not typed as tenant. Mirrors
     * {@see AdminController::isOperatorArea()} so the nav grouping and the access gate
     * agree on the operator/tenant boundary.
     */
    private function isOperatorArea(string $area): bool
    {
        return array_key_exists($area, AdminController::NAV)
            && !in_array($area, AdminController::TENANT_AREAS, true);
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
