<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Admin\AdminNavBuilder;
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
        $menu = (new AdminNavBuilder())->menu($this->userAreaKeys);
        $this->set('heading', 'admin.nav.modules');
        $this->set('groups', $menu['module']);
        $this->set('metrics', $this->tileMetrics());
        $this->set('activeTop', 'module');
        $this->viewBuilder()->setTemplate('landing');
    }

    /** "Administration" landing: tiles for the Core admin areas + system. */
    public function administration(): void
    {
        $menu = (new AdminNavBuilder())->menu($this->userAreaKeys);
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

        // Users by status: the headline is the active count, the detail spells out
        // the full breakdown (active · invited · disabled · anonymized).
        $byUserStatus = [];
        foreach ($conn->execute('SELECT status, count(*) c FROM users GROUP BY status')->fetchAll('assoc') as $r) {
            $byUserStatus[(string)$r['status']] = (int)$r['c'];
        }
        $userDetail = [];
        foreach (['active', 'invited', 'disabled', 'anonymized'] as $s) {
            if (($byUserStatus[$s] ?? 0) > 0) {
                $userDetail[] = $byUserStatus[$s] . ' ' . __('admin.metric.user_' . $s);
            }
        }

        $simple = static fn(string $sql): array => ['badge' => (string)$count($sql), 'detail' => ''];

        return [
            '/admin/users' => [
                'badge' => (string)($byUserStatus['active'] ?? 0),
                'detail' => implode(' · ', $userDetail),
            ],
            '/admin/groups' => $simple('SELECT count(*) c FROM "groups" WHERE active'),
            '/admin/modules' => $simple("SELECT count(*) c FROM modules WHERE status = 'active'"),
            '/admin/registry' => $simple('SELECT count(*) c FROM contracts WHERE active'),
            '/admin/marketplace/licenses' => $simple('SELECT count(*) c FROM licenses'),
            '/admin/outbox' => $simple("SELECT count(*) c FROM event_outbox WHERE status = 'pending'"),
        ];
    }
}
