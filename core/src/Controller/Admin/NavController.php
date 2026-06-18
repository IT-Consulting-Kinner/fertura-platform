<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Admin\AdminNavBuilder;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Exception\ForbiddenException;

/**
 * Tile-based admin navigation. The top menu has three entries — Dashboard,
 * Module, Administration — and the latter two are landing pages that render the
 * scoped navigation as tiles instead of a sidebar. Drilling into a group shows
 * its items as tiles (e.g. "Users & groups" -> Users + Groups).
 *
 * Grouping is derived from the same data-driven, area-scoped navigation as the
 * old sidebar ({@see AdminNavBuilder}): the Core areas in
 * {@see AdminController::NAV} plus the admin pages contributed by active modules.
 * "Module" gathers the module-lifecycle area and every module-contributed area;
 * "Administration" gathers the remaining Core areas plus the area-less system
 * pages. Visibility stays server-side authorization — a user only sees (and may
 * open) the areas they hold.
 */
class NavController extends AdminController
{
    /** Always-available system pages (no admin-area gate; any admin may open). */
    private const SYSTEM = [
        'label' => 'admin.nav.system',
        'items' => [
            ['admin.nav.health', '/admin/health'],
            ['admin.nav.audit', '/admin/audit'],
            ['admin.nav.api_tokens', '/admin/tokens'],
        ],
    ];

    /** Core areas shown under "Administration" (module_lifecycle -> "Module"). */
    private const ADMIN_AREAS = [
        'user_group_admin', 'core_config', 'registry_contracts',
        'localization', 'update_manager', 'marketplace_license',
    ];

    /** "Module" landing: module-lifecycle + every module-contributed area. */
    public function modules(): void
    {
        $nav = (new AdminNavBuilder())->build($this->userAreaKeys);
        $coreKeys = array_keys(AdminController::NAV);

        $groups = [];
        if (isset($nav['module_lifecycle'])) {
            $groups['module_lifecycle'] = $nav['module_lifecycle'];
        }
        foreach ($nav as $key => $def) {
            if (!in_array($key, $coreKeys, true)) {
                $groups[$key] = $def;
            }
        }

        $this->set('heading', 'admin.nav.modules');
        $this->set('groups', $groups);
        $this->set('metrics', $this->tileMetrics());
        $this->set('activeTop', 'module');
    }

    /** "Administration" landing: the remaining Core areas + the system group. */
    public function administration(): void
    {
        $nav = (new AdminNavBuilder())->build($this->userAreaKeys);

        $groups = [];
        foreach (self::ADMIN_AREAS as $key) {
            if (isset($nav[$key])) {
                $groups[$key] = $nav[$key];
            }
        }
        $groups['system'] = self::SYSTEM;

        $this->set('heading', 'admin.nav.administration');
        $this->set('groups', $groups);
        $this->set('metrics', $this->tileMetrics());
        $this->set('activeTop', 'administration');
    }

    /** Drill-down: one group's items rendered as tiles. */
    public function section(string $area): void
    {
        if ($area === 'system') {
            $def = self::SYSTEM;
            $top = 'administration';
        } else {
            $nav = (new AdminNavBuilder())->build($this->userAreaKeys);
            if (!isset($nav[$area])) {
                // Not held (or unknown) -> same fail-closed behaviour as a gated page.
                throw new ForbiddenException('Kein Zugriff auf diesen Administrationsbereich.');
            }
            $def = $nav[$area];
            $isCoreAdmin = in_array($area, self::ADMIN_AREAS, true);
            $top = $isCoreAdmin ? 'administration' : 'module';
        }

        $this->set('sectionDef', $def);
        $this->set('metrics', $this->tileMetrics());
        $this->set('activeTop', $top);
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
