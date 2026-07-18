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
