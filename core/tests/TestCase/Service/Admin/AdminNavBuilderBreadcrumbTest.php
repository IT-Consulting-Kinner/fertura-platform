<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Admin;

use App\Service\Admin\AdminNavBuilder;
use App\Service\Module\WebRouteRegistry;
use Cake\TestSuite\TestCase;

/**
 * Shared breadcrumb builder (ch. 23.16.3): ONE trail implementation for Core
 * admin pages (AdminController) and module admin pages (ModuleWebController) —
 * top menu → section tile (only when the area drills down) → current function
 * via longest-prefix match over the area's nav items. Regression for module
 * sub-pages, which used to get a hardcoded two-level default without the area
 * crumb ("Modul → <Seitentitel>" instead of "Modul → Ticketing → <Funktion>").
 */
class AdminNavBuilderBreadcrumbTest extends TestCase
{
    /**
     * Builder with a stubbed module contribution (no DB).
     *
     * @param array<string, array{label:string, items:list<array{0:string,1:string}>}> $adminNav
     */
    private function builder(array $adminNav = []): AdminNavBuilder
    {
        $registry = new class ($adminNav) extends WebRouteRegistry {
            /** @param array<string, array{label:string, items:list<array{0:string,1:string}>}> $nav */
            public function __construct(private array $nav)
            {
            }

            public function adminNav(): array
            {
                return $this->nav;
            }
        };

        return new AdminNavBuilder($registry);
    }

    public function testCoreAreaListPageEndsOnUnlinkedLeaf(): void
    {
        $trail = $this->builder()->breadcrumb(['user_group_admin'], 'user_group_admin', '/admin/users');

        $this->assertSame([
            ['admin.nav.modules', '/admin/module'],
            ['admin.nav.users_groups', '/admin/section/user_group_admin'],
            ['admin.nav.users', null],
        ], $trail);
    }

    public function testCoreSubPageLinksBackToItsListLeaf(): void
    {
        $trail = $this->builder()->breadcrumb(['user_group_admin'], 'user_group_admin', '/admin/users/view/0123');

        $this->assertSame(['admin.nav.users', '/admin/users'], $trail[2]);
    }

    public function testSingleItemAreaCollapsesToGroupLabel(): void
    {
        // update_manager has exactly one nav item: no drill-down tile page exists,
        // the group label IS the function (and the area is operator realm).
        $trail = $this->builder()->breadcrumb(['update_manager'], 'update_manager', '/admin/updates');

        $this->assertSame([
            ['admin.nav.administration', '/admin/administration'],
            ['admin.nav.updates', null],
        ], $trail);
    }

    public function testSingleItemAreaOnOtherPathLinksToTheFunction(): void
    {
        // Adversarial-review finding: the collapsed group label may only claim
        // "current page" when the request really is that page. On any other path
        // (Core sub-page like /admin/localization/edit, or a nav-less module
        // route in the area) it links to the function so the caller can append
        // the actual page as the active crumb.
        $trail = $this->builder()->breadcrumb(['update_manager'], 'update_manager', '/admin/updates/history');

        $this->assertSame([
            ['admin.nav.administration', '/admin/administration'],
            ['admin.nav.updates', '/admin/updates'],
        ], $trail);
    }

    public function testSingleItemModuleAreaNavlessRouteLinksToTheFunction(): void
    {
        $nav = ['zx_admin' => ['label' => 'ZX', 'items' => [['Einstellungen', '/m/zx/admin/settings']]]];
        $trail = $this->builder($nav)->breadcrumb(['zx_admin'], 'zx_admin', '/m/zx/admin/import');

        $this->assertSame([
            ['admin.nav.modules', '/admin/module'],
            ['ZX', '/m/zx/admin/settings'],
        ], $trail);
    }

    public function testModuleLifecycleKeepsItsClearerLabel(): void
    {
        $trail = $this->builder()->breadcrumb(['module_lifecycle'], 'module_lifecycle', '/admin/modules');

        $this->assertSame([
            ['admin.nav.administration', '/admin/administration'],
            ['admin.nav.module_management', null],
        ], $trail);
    }

    public function testModuleAreaPageGetsTheAreaCrumb(): void
    {
        // THE regression: a module admin page must carry the area (group) crumb —
        // labels arrive pre-resolved from WebRouteRegistry::adminNav().
        $nav = ['zt_admin' => ['label' => 'Ticketing', 'items' => [
            ['Queue-Gruppen', '/m/zt/admin/queue-groups'],
            ['Queues', '/m/zt/admin/queues'],
        ]]];
        $trail = $this->builder($nav)->breadcrumb(['zt_admin'], 'zt_admin', '/m/zt/admin/queue-groups');

        $this->assertSame([
            ['admin.nav.modules', '/admin/module'],
            ['Ticketing', '/admin/section/zt_admin'],
            ['Queue-Gruppen', null],
        ], $trail);
    }

    public function testModuleAreaSubPageLinksBackToItsListLeaf(): void
    {
        $nav = ['zt_admin' => ['label' => 'Ticketing', 'items' => [
            ['Queue-Gruppen', '/m/zt/admin/queue-groups'],
            ['Queues', '/m/zt/admin/queues'],
        ]]];
        $trail = $this->builder($nav)->breadcrumb(['zt_admin'], 'zt_admin', '/m/zt/admin/queue-groups/edit/7');

        $this->assertSame(['Queue-Gruppen', '/m/zt/admin/queue-groups'], $trail[2]);
    }

    public function testUnknownOrUnheldAreaFallsBackToTopCrumbOnly(): void
    {
        $trail = $this->builder()->breadcrumb([], 'zt_admin', '/m/zt/admin/queue-groups');

        $this->assertSame([['admin.nav.modules', '/admin/module']], $trail);
    }
}
