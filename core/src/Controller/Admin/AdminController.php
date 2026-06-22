<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Infrastructure\Uuid;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Tenant\TenantService;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Response;

/**
 * Base class for all admin controllers (scoped admin, ch. 27.3.1 / Decision 170).
 *
 * Enforces server-side: authenticated + the user holds the required
 * administration area. Navigation is scoped to the held areas
 * (visibility = server-side authorization, ch. 27.16.2).
 */
class AdminController extends AppController
{
    /**
     * The operator tenant: the platform's default tenant, whose users are the
     * OPERATOR admins (Betreiber/Mandant-Trennung, design §3.1). Operator-scoped
     * areas (platform-wide functions) require membership here.
     */
    public const OPERATOR_TENANT_ID = TenantService::DEFAULT_TENANT_ID;

    /**
     * Core areas that are TENANT-scoped (administer within one tenant). Every OTHER
     * Core area in {@see self::NAV} is operator-scoped; module-contributed areas (not
     * in NAV) are tenant config. Fail-closed: a NEW Core area is operator until listed
     * here. The area SPLIT (op_admins vs tenant_users etc.) lands in a later increment;
     * here user_group_admin stays one tenant area (the operator's own user mgmt is the
     * operator-tenant slice of it).
     */
    public const TENANT_AREAS = ['user_group_admin', 'tenant_modules'];

    /**
     * Required administration area (null = any admin).
     */
    protected ?string $requiredArea = null;

    /**
     * Operator-only WITHOUT a fixed area (e.g. the platform health dashboard): forces
     * the operator-tenant gate even when {@see self::$requiredArea} is null. Use for
     * gate-free admin pages that surface operator/platform-wide data which a tenant
     * admin must not see.
     */
    protected bool $requiresOperator = false;

    /**
     * @var list<string>
     */
    protected array $userAreaKeys = [];

    /** Area navigation (area key => label + menu items). */
    public const NAV = [
        'user_group_admin' => [
            'label' => 'admin.nav.users_groups',
            'items' => [['admin.nav.users', '/admin/users'], ['admin.nav.groups', '/admin/groups']],
        ],
        'tenant_modules' => [
            'label' => 'admin.nav.tenant_modules',
            'items' => [['admin.nav.tenant_modules_item', '/admin/tenant-modules']],
        ],
        'module_lifecycle' => [
            'label' => 'admin.nav.modules',
            'items' => [['admin.nav.module_list', '/admin/modules']],
        ],
        'marketplace_license' => [
            'label' => 'admin.nav.marketplace',
            'items' => [['admin.nav.marketplace_item', '/admin/marketplace'], ['admin.nav.licenses', '/admin/marketplace/licenses']],
        ],
        'registry_contracts' => [
            'label' => 'admin.nav.registry',
            'items' => [['admin.nav.contracts', '/admin/registry'], ['admin.nav.interfaces', '/admin/registry/interfaces']],
        ],
        'update_manager' => [
            'label' => 'admin.nav.updates',
            'items' => [['admin.nav.update_manager_item', '/admin/updates']],
        ],
        'core_config' => [
            'label' => 'admin.nav.config',
            'items' => [['admin.nav.settings', '/admin/config'], ['admin.nav.integrations', '/admin/integrations'], ['admin.nav.tenants', '/admin/tenants'], ['admin.nav.outbox', '/admin/outbox'], ['admin.nav.backup', '/admin/backup'], ['admin.nav.trust', '/admin/trust']],
        ],
        'localization' => [
            'label' => 'admin.nav.localization',
            'items' => [['admin.nav.language_packs', '/admin/localization']],
        ],
        'system_maintenance' => [
            'label' => 'admin.nav.maintenance',
            'items' => [['admin.nav.maintenance_item', '/admin/maintenance']],
        ],
    ];

    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setLayout('admin');
    }

    public function beforeFilter(EventInterface $event): ?Response
    {
        parent::beforeFilter($event);

        $identity = $this->identity();
        if ($identity === null) {
            return $this->redirect('/login');
        }

        $this->userAreaKeys = $this->loadUserAreas((string)$identity->getIdentifier());
        if ($this->userAreaKeys === []) {
            throw new ForbiddenException('Kein Administrationszugriff.');
        }
        if ($this->requiredArea !== null && !in_array($this->requiredArea, $this->userAreaKeys, true)) {
            throw new ForbiddenException('Kein Zugriff auf diesen Administrationsbereich.');
        }
        // Operator gate (Betreiber/Mandant-Trennung, Inc 1): an operator-scoped area is
        // a platform-wide function (tenants, updates, module lifecycle, system backup,
        // maintenance, …) and may be reached ONLY by an operator admin — a user of the
        // operator tenant. A tenant admin holding such a grant is still refused here, so
        // the grant alone never crosses the operator boundary. `$requiresOperator`
        // extends this to gate-free operator pages (no fixed area). Tenant-scoped +
        // module areas pass (their data isolation is enforced per-controller).
        $needsOperator = $this->requiresOperator
            || ($this->requiredArea !== null && $this->isOperatorArea($this->requiredArea));
        if ($needsOperator && !$this->isOperatorTenant()) {
            throw new ForbiddenException('Operator-Bereich: nur fuer Betreiber-Administratoren.');
        }

        $this->set('currentUser', $identity);
        $this->set('userAreas', $this->userAreaKeys);
        $this->set('navAreas', (new AdminNavBuilder())->build($this->userAreaKeys));
        // Top-menu dropdowns (Module / Administration) for the layout.
        $this->set('topMenu', (new AdminNavBuilder())->menu($this->userAreaKeys));
        $this->set('activeArea', $this->requiredArea);
        // Which top-menu entry (Dashboard/Module/Administration) is active. Nav
        // controllers override this per landing; module-lifecycle and module-
        // contributed areas live under "Module", the remaining Core areas under
        // "Administration" (area-less system pages too).
        $this->set('activeTop', $this->computeActiveTop());
        // Breadcrumb for function pages (top menu → section tiles → function), so
        // every page links back up. Nav landing/section pages set their own.
        $this->set('breadcrumb', $this->computeBreadcrumb());

        return null;
    }

    /**
     * Breadcrumb for the current admin function page: top menu → section (the tile
     * drill-down) → the current function. Empty for the dashboard and area-less
     * system pages. The Nav landing/section pages override it with their own.
     *
     * @return list<array{0:string,1:?string}> [label-key, url|null]; null = current
     */
    private function computeBreadcrumb(): array
    {
        if ($this->requiredArea === null || $this->computeActiveTop() === 'dashboard') {
            return [];
        }
        $top = $this->computeActiveTop();
        $crumbs = [[$top === 'module' ? 'admin.nav.modules' : 'admin.nav.administration', '/admin/' . $top]];

        $def = ((new AdminNavBuilder())->build($this->userAreaKeys))[$this->requiredArea] ?? null;
        if ($def === null) {
            return $crumbs;
        }
        $label = $this->requiredArea === 'module_lifecycle' ? 'admin.nav.module_management' : $def['label'];
        $items = $def['items'];

        if (count($items) === 1) {
            // No drill-down tile page exists: the group label IS the function.
            $crumbs[] = [$label, null];

            return $crumbs;
        }

        // Section tile page, then the current function — found by longest-prefix
        // match so sub-pages (add/edit/view) still resolve to their leaf, which
        // then links back to the leaf's list page.
        $crumbs[] = [$label, '/admin/section/' . $this->requiredArea];
        $path = $this->getRequest()->getUri()->getPath();
        $best = null;
        $bestLen = -1;
        foreach ($items as $it) {
            $url = (string)$it[1];
            if (str_starts_with($path, $url) && strlen($url) > $bestLen) {
                $best = $it;
                $bestLen = strlen($url);
            }
        }
        if ($best !== null) {
            $onLeaf = rtrim($path, '/') === rtrim((string)$best[1], '/');
            $crumbs[] = [(string)$best[0], $onLeaf ? null : (string)$best[1]];
        }

        return $crumbs;
    }

    /** Maps the current page to its top-menu entry for highlighting. */
    private function computeActiveTop(): string
    {
        if ((string)$this->getRequest()->getParam('controller') === 'Dashboard') {
            return 'dashboard';
        }
        if ($this->requiredArea === null) {
            return 'administration';
        }

        // Realm by scope: operator areas highlight the operator realm (Betreiber,
        // internal key 'administration'), tenant + module areas the tenant realm
        // (Mandant, internal key 'module').
        return $this->isOperatorArea($this->requiredArea) ? 'administration' : 'module';
    }

    /**
     * UUID guard for route/form parameters that are bound in raw SQL against
     * PG uuid columns: malformed values would raise 22P02 there
     * (-> HTTP 500) instead of being treated as "not found" like unknown IDs
     * (cf. \App\Infrastructure\Uuid).
     */
    protected function isUuid(string ...$values): bool
    {
        foreach ($values as $value) {
            if (!Uuid::isValid($value)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    protected function loadUserAreas(string $userId): array
    {
        $rows = ConnectionManager::get('default')->execute(
            'SELECT admin_area_key FROM user_admin_areas WHERE user_id = :u',
            ['u' => $userId],
        )->fetchAll('assoc');

        return array_map(static fn($r) => (string)$r['admin_area_key'], $rows);
    }

    /**
     * Whether $area is OPERATOR-scoped: a Core area ({@see self::NAV}) not listed in
     * {@see self::TENANT_AREAS}. Module-contributed areas (absent from NAV) are tenant
     * config, never operator. Fail-closed for Core: a new Core area is operator by
     * default.
     */
    protected function isOperatorArea(string $area): bool
    {
        return array_key_exists($area, self::NAV) && !in_array($area, self::TENANT_AREAS, true);
    }

    /**
     * Whether the acting admin belongs to the operator tenant (the gate for
     * operator-scoped areas). Reads the request's RLS tenant context; NULL/unset
     * yields false (fail-closed). Single-org installs run entirely in the operator
     * (default) tenant, so this is true for everyone — backward-compatible.
     */
    protected function isOperatorTenant(): bool
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        $row = $conn->execute(
            'SELECT core.current_tenant() = :op AS ok',
            ['op' => self::OPERATOR_TENANT_ID],
        )->fetch('assoc');

        return $row !== false && ($row['ok'] === true || $row['ok'] === 't');
    }
}
