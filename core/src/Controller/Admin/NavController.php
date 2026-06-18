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

        $this->set('sectionDef', $def);
        $this->set('metrics', $this->tileMetrics());
        $this->set('activeTop', $builder->areaTop($area));
    }

    /**
     * Count badges shown on the tiles (the "Zusatzinformation"). Keyed by the
     * item URL; only the cheap, obviously-useful counts. Mirrors the dashboard
     * queries (search_path includes `core`).
     *
     * @return array<string, int>
     */
    private function tileMetrics(): array
    {
        $conn = ConnectionManager::get('default');
        $count = static fn(string $sql): int => (int)($conn->execute($sql)->fetch('assoc')['c'] ?? 0);

        return [
            '/admin/users' => $count("SELECT count(*) c FROM users WHERE status = 'active'"),
            '/admin/groups' => $count('SELECT count(*) c FROM "groups" WHERE active'),
            '/admin/modules' => $count("SELECT count(*) c FROM modules WHERE status = 'active'"),
            '/admin/registry' => $count('SELECT count(*) c FROM contracts WHERE active'),
            '/admin/marketplace/licenses' => $count('SELECT count(*) c FROM licenses'),
            '/admin/outbox' => $count("SELECT count(*) c FROM event_outbox WHERE status = 'pending'"),
        ];
    }
}
