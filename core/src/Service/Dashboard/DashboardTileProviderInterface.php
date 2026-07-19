<?php
declare(strict_types=1);

namespace App\Service\Dashboard;

/**
 * A module contributes admin-dashboard tiles by implementing this interface and
 * registering it on the `core.collector.dashboard_tiles` collector (Paket 4).
 * The Core renders the returned tiles in the module's own accordion group.
 *
 * Return a list of tiles; each is pure data (the Core coerces + escapes it):
 *   [
 *     'label'   => string,           // already localized by the module
 *     'value'   => string|int,       // the figure/status shown big
 *     'url'     => string|null,      // OPTIONAL app-relative link (/m/<key>/…); off-site is dropped
 *     'variant' => string|null,      // OPTIONAL Bootstrap variant for emphasis (e.g. 'danger')
 *   ]
 *
 * TENANT-SCOPED audience: the collector is invoked ONLY for a tenant that has
 * the module enabled (per-tenant gate, operator/tenant authz §5). Its tiles are
 * therefore shown to every admin of such a tenant — the tenant admin sees the
 * KPIs of the modules THEIR tenant runs. A provider MUST NOT gate its whole
 * output on `context['is_operator']`: doing so hides the tiles from exactly the
 * tenant admins they are for, and in multi_org hides them from everyone (the
 * operator/default tenant is module-free, so it is the one tenant where the
 * module is never enabled). `is_operator` is INFORMATIONAL only — use it at most
 * to ADD an operator-specific tile, never to suppress the whole list. Query the
 * tenant's own data (RLS scopes it to the current tenant).
 *
 * Runs in-process within the request (RLS tenant context + the module's storage
 * scope apply). Keep it cheap: it is on the dashboard's hot path.
 */
interface DashboardTileProviderInterface
{
    /**
     * @param array{locale: string, is_operator: bool} $context
     * @return list<array<string, mixed>>
     */
    public function dashboardTiles(array $context): array;
}
