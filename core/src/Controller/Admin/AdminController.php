<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Infrastructure\Uuid;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\ListPrefsService;
use App\Service\Permission\AdminAreaResolver;
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
    public const TENANT_AREAS = ['user_group_admin', 'tenant_modules', 'tenant_backup', 'consumption'];

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
        'tenant_backup' => [
            'label' => 'admin.nav.tenant_backup',
            'items' => [['admin.nav.tenant_backup_item', '/admin/tenant-backup']],
        ],
        'consumption' => [
            'label' => 'admin.nav.consumption',
            'items' => [['admin.nav.consumption_item', '/admin/consumption']],
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
            'items' => [['admin.nav.settings', '/admin/config'], ['admin.nav.integrations', '/admin/integrations'], ['admin.nav.tenants', '/admin/tenants'], ['admin.nav.operator_consumption', '/admin/operator-consumption'], ['admin.nav.outbox', '/admin/outbox'], ['admin.nav.backup', '/admin/backup'], ['admin.nav.trust', '/admin/trust']],
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
        // Top-menu dropdowns (Module / Administration) for the layout, narrowed to the
        // viewer's tenant realm(s) (operator-tenant design §6/§5a).
        $this->set('topMenu', $this->topMenuForViewer());
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

        // Shared with ModuleWebController so Core and module admin pages render
        // the identical trail (the builder mirrors computeActiveTop's realm split).
        return (new AdminNavBuilder())->breadcrumb(
            $this->userAreaKeys,
            $this->requiredArea,
            $this->getRequest()->getUri()->getPath(),
        );
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

        // Which realm the area is DISPLAYED in — delegated to the nav builder so the
        // top-menu highlight, breadcrumb root and menu grouping agree on ONE mapping
        // (display realm != access scope: user/group mgmt shows under Administration
        // yet stays tenant-reachable; the operator gate above uses isOperatorArea()).
        return (new AdminNavBuilder())->areaTop($this->requiredArea);
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

    /**
     * The admin areas the user effectively holds — resolved from GROUP membership
     * ({@see AdminAreaResolver}: union of the user's active groups' granted areas,
     * or ALL areas for a member of the Administrators/is_system group).
     *
     * @return list<string>
     */
    protected function loadUserAreas(string $userId): array
    {
        return (new AdminAreaResolver())->areasFor($userId);
    }

    /** The acting admin's user id, or '' when unauthenticated (fail-closed). */
    protected function actingUserId(): string
    {
        $id = $this->identity()?->getIdentifier();

        return is_scalar($id) ? (string)$id : '';
    }

    /**
     * Resolves the effective state of a paginated admin list from the request +
     * the user's STORED per-list preferences (Paket 2). A visit that carries the
     * `_lp` marker (filter submit, reset, pagination link) is authoritative and
     * is PERSISTED; a fresh visit without it reapplies the stored state. So a
     * user's chosen filters and page size survive navigating away and back.
     *
     * `page` always comes from the request (never persisted). `per_page` is
     * clamped to `$perPageOptions`. Returns the effective filters/per_page/page
     * plus the query bag to hand to `UiKit->paginate()` so its links carry the
     * state forward.
     *
     * @param list<string> $filterKeys the filter query params this list understands
     * @param list<int> $perPageOptions
     * @return array{
     *   filters: array<string,string>, per_page: int, page: int,
     *   query: array<string,mixed>
     * }
     */
    protected function resolveListState(
        string $listKey,
        array $filterKeys,
        int $defaultPerPage = 50,
        array $perPageOptions = [25, 50, 100, 200],
    ): array {
        $req = $this->getRequest();
        $userId = $this->actingUserId();
        $service = new ListPrefsService();
        // The query is AUTHORITATIVE for this render (its values are applied
        // instead of the stored ones) when the request carries any relevant
        // param: the `_lp` marker, a per_page choice, or any filter key — even
        // one present-but-empty, so a hand-cleared filter is honoured. A truly
        // bare visit carries none and reapplies the stored preference.
        $applyQuery = $req->getQuery('_lp') !== null || $req->getQuery('per_page') !== null;
        foreach ($filterKeys as $k) {
            if ($req->getQuery($k) !== null) {
                $applyQuery = true;
                break;
            }
        }
        // PERSIST only on the explicit `_lp` marker — which the app's own filter
        // form, reset link and pagination emit, but a hand-typed/deep-linked URL
        // does not — and never for a cross-site request (Sec-Fetch-Site), so a
        // forged `<img src=…?_lp=1>` or a prefetch cannot silently rewrite a
        // logged-in admin's saved list state (GET has no CSRF token).
        $crossSite = strtolower(trim($req->getHeaderLine('Sec-Fetch-Site'))) === 'cross-site';
        $persist = $req->getQuery('_lp') !== null && !$crossSite && $userId !== '';

        $filters = [];
        if ($applyQuery) {
            foreach ($filterKeys as $k) {
                $filters[$k] = trim((string)$req->getQuery($k, ''));
            }
            $perPage = (int)$req->getQuery('per_page', (string)$defaultPerPage);
            if (!in_array($perPage, $perPageOptions, true)) {
                $perPage = $defaultPerPage;
            }
            if ($persist) {
                $service->save($userId, $listKey, $perPage, $filters);
            }
        } else {
            $stored = $userId !== '' ? $service->load($userId, $listKey) : ['per_page' => null, 'filters' => []];
            foreach ($filterKeys as $k) {
                $filters[$k] = (string)($stored['filters'][$k] ?? '');
            }
            $perPage = $stored['per_page'] ?? $defaultPerPage;
            if (!in_array($perPage, $perPageOptions, true)) {
                $perPage = $defaultPerPage;
            }
        }

        $page = max(1, (int)$req->getQuery('page', '1'));
        // Query bag for pagination links: the active filters + per_page + the
        // marker, so paging keeps the state (and re-persists it) without the
        // template needing to know the storage mechanism.
        $query = array_filter($filters, static fn($v) => $v !== '') + ['per_page' => $perPage, '_lp' => 1];

        return ['filters' => $filters, 'per_page' => $perPage, 'page' => $page, 'query' => $query];
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

    /**
     * The top menu narrowed to the viewer's tenant realm(s): customer-tenant admins do
     * not see the operator (Betreiber) realm, single-org / operator admins see both
     * (operator-tenant design §6/§5a, {@see AdminNavBuilder::menu}). Module functions of
     * an operator tenant in a multi-tenant install are already gone from the menu via
     * the per-tenant enablement gate.
     *
     * @return array{module: array<string,array{label:string,items:list<array{0:string,1:string}>}>, administration: array<string,array{label:string,items:list<array{0:string,1:string}>}>}
     */
    protected function topMenuForViewer(): array
    {
        return (new AdminNavBuilder())->menu($this->userAreaKeys, $this->isOperatorTenant());
    }
}
