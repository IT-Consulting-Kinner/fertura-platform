<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\TileMetrics;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Exception\ForbiddenException;

/**
 * Tile-based admin navigation. The top menu has Dashboard plus two dropdowns —
 * Module and Administration — whose entries are the scoped nav groups
 * ({@see AdminNavBuilder::menu()}). Selecting a group opens its drill-down page,
 * which renders the group's items as tiles (e.g. "Users & groups" -> Users +
 * Groups). Also serves the lightweight self-profile page.
 *
 * Grouping stays data-driven and area-scoped: a user only sees (and may open) the
 * areas they hold. "Module" holds the module-contributed setting areas (Ticketing,
 * Knowledgebase, …); "Administration" holds the Core areas incl. module management
 * plus the always-available system pages.
 */
class NavController extends AdminController
{
    /** "Module" landing: tiles for the module-contributed setting areas. */
    public function modules(): void
    {
        $menu = $this->topMenuForViewer();
        $this->set('heading', 'admin.nav.modules');
        $this->set('groups', $menu['module']);
        $this->set('metrics', $this->tileMetrics());
        $this->set('activeTop', 'module');
        $this->viewBuilder()->setTemplate('landing');
    }

    /** "Administration" landing: tiles for the Core admin areas + system. */
    public function administration(): void
    {
        $menu = $this->topMenuForViewer();
        $this->set('heading', 'admin.nav.administration');
        $this->set('groups', $menu['administration']);
        $this->set('metrics', $this->tileMetrics());
        $this->set('activeTop', 'administration');
        $this->viewBuilder()->setTemplate('landing');
    }

    /** Drill-down: one group's items rendered as tiles. */
    public function section(string $area): void
    {
        $builder = new AdminNavBuilder();

        if ($area === 'system') {
            $def = AdminNavBuilder::SYSTEM;
        } else {
            $nav = $builder->build($this->userAreaKeys);
            if (!isset($nav[$area])) {
                // Not held (or unknown) -> same fail-closed behaviour as a gated page.
                throw new ForbiddenException('Kein Zugriff auf diesen Administrationsbereich.');
            }
            $def = $nav[$area];
            if ($area === 'module_lifecycle') {
                $def['label'] = 'admin.nav.module_management';
            }
        }

        $top = $builder->areaTop($area);
        $this->set('sectionDef', $def);
        $this->set('metrics', $this->tileMetrics());
        $this->set('activeTop', $top);
        // Breadcrumb: top menu → this section (current). The shared element in the
        // admin layout renders it; the section template no longer hand-rolls one.
        $this->set('breadcrumb', [
            [$top === 'module' ? 'admin.nav.modules' : 'admin.nav.administration', '/admin/' . $top],
            [$def['label'], null],
        ]);
    }

    /**
     * Per-tile "Zusatzinformation" keyed by the item URL: a headline `badge`
     * (count) plus an optional `detail` sub-line (e.g. a by-status breakdown).
     * Only cheap, obviously-useful aggregates. Mirrors the dashboard queries
     * (search_path includes `core`).
     *
     * @return array<string, array{badge: string, detail: string}>
     */
    private function tileMetrics(): array
    {
        // RLS-effective default connection. Narrow to the concrete Connection so
        // execute() resolves; ConnectionInterface intentionally omits it.
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        $count = static fn(string $sql): int => (int)($conn->execute($sql)->fetch('assoc')['c'] ?? 0);

        // Every COLLECTION tile shares one shape: badge = active count, detail =
        // "x aktiv / y inaktiv" ({@see \App\Service\Admin\TileMetrics}). Scoped where
        // the table has no RLS.
        $ai = static function (string $activeSql, string $totalSql) use ($count): array {
            $active = $count($activeSql);

            return TileMetrics::activeInactive($active, $count($totalSql) - $active);
        };

        $metrics = [
            // users has no RLS -> scope explicitly to the current tenant.
            '/admin/users' => $ai(
                "SELECT count(*) c FROM users WHERE status = 'active' AND tenant_id = core.current_tenant()",
                'SELECT count(*) c FROM users WHERE tenant_id = core.current_tenant()',
            ),
            // groups is RLS-scoped to the current tenant already.
            '/admin/groups' => $ai(
                'SELECT count(*) c FROM "groups" WHERE active',
                'SELECT count(*) c FROM "groups"',
            ),
            // tenant_modules / tenant_backup_plans are RLS-scoped to the current
            // tenant already; a tenant admin sees these under the Module realm, so
            // compute them unconditionally (RLS keeps them cheap and tenant-correct).
            // "active" maps to the module-enablement flag for tenant_modules.
            '/admin/tenant-modules' => $ai(
                'SELECT count(*) c FROM tenant_modules WHERE enabled',
                'SELECT count(*) c FROM tenant_modules',
            ),
            '/admin/tenant-backup' => $ai(
                'SELECT count(*) c FROM tenant_backup_plans WHERE active',
                'SELECT count(*) c FROM tenant_backup_plans',
            ),
        ];

        // Operator-domain tiles count platform-wide, non-tenant-scoped tables. Tenant
        // admins do not hold these areas (so the tiles aren't rendered for them); only
        // compute them for operators, so the counts are neither leaked nor wasted.
        if ($this->isOperatorTenant()) {
            $metrics += [
                '/admin/modules' => $ai(
                    "SELECT count(*) c FROM modules WHERE status = 'active'",
                    'SELECT count(*) c FROM modules',
                ),
                '/admin/registry' => $ai(
                    'SELECT count(*) c FROM contracts WHERE active',
                    'SELECT count(*) c FROM contracts',
                ),
                // Operator-global collections (no tenant_id / no RLS): the tenant
                // registry and the trust anchors. Operator-only, so counting the
                // whole platform is correct here, not a cross-tenant leak.
                '/admin/tenants' => $ai(
                    'SELECT count(*) c FROM tenants WHERE active',
                    'SELECT count(*) c FROM tenants',
                ),
                '/admin/trust' => $ai(
                    'SELECT count(*) c FROM trust_anchors WHERE active',
                    'SELECT count(*) c FROM trust_anchors',
                ),
                // NOT active/inactive collections: a queue (pending events) and a
                // service-owned license-validity concept keep their own single detail.
                '/admin/outbox' => TileMetrics::single(
                    $count("SELECT count(*) c FROM event_outbox WHERE status = 'pending'"),
                    'admin.metric.pending',
                ),
                '/admin/marketplace/licenses' => TileMetrics::single(
                    $count('SELECT count(*) c FROM licenses'),
                    'admin.metric.total',
                ),
            ];
        }

        return $metrics;
    }
}
