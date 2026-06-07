<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;

/**
 * Basis für alle Admin-Controller (scoped admin, Kap. 27.3.1 / Entscheidung 170).
 *
 * Erzwingt serverseitig: angemeldet + Benutzer hält den geforderten
 * Administrationsbereich. Die Navigation wird auf die gehaltenen Bereiche
 * gescoped (Sichtbarkeit = serverseitige Berechtigung, Kap. 27.16.2).
 */
class AdminController extends AppController
{
    /** Geforderter Administrationsbereich (null = jeder Admin). */
    protected ?string $requiredArea = null;

    /** @var list<string> */
    protected array $userAreaKeys = [];

    /** Bereichs-Navigation (Bereich-Key => Label + Menüpunkte). */
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
            'items' => [['admin.nav.settings', '/admin/config'], ['admin.nav.outbox', '/admin/outbox'], ['admin.nav.backup', '/admin/backup']],
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

    public function beforeFilter(EventInterface $event)
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

        $nav = [];
        foreach (self::NAV as $key => $def) {
            if (in_array($key, $this->userAreaKeys, true)) {
                $nav[$key] = $def;
            }
        }

        $this->set('currentUser', $identity);
        $this->set('userAreas', $this->userAreaKeys);
        $this->set('navAreas', $nav);
        $this->set('activeArea', $this->requiredArea);

        return null;
    }

    /** @return list<string> */
    protected function loadUserAreas(string $userId): array
    {
        $rows = ConnectionManager::get('default')->execute(
            'SELECT admin_area_key FROM user_admin_areas WHERE user_id = :u',
            ['u' => $userId],
        )->fetchAll('assoc');

        return array_map(static fn ($r) => (string)$r['admin_area_key'], $rows);
    }
}
