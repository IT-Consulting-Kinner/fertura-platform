<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Infrastructure\Uuid;
use App\Service\Admin\AdminNavBuilder;
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
     * Required administration area (null = any admin).
     */
    protected ?string $requiredArea = null;

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

        return null;
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

        return in_array($this->requiredArea, AdminNavBuilder::ADMIN_ORDER, true) ? 'administration' : 'module';
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
}
