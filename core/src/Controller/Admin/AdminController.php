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
            'label' => 'Benutzer & Gruppen',
            'items' => [['Benutzer', '/admin/users'], ['Gruppen', '/admin/groups']],
        ],
        'module_lifecycle' => [
            'label' => 'Module',
            'items' => [['Module', '/admin/modules']],
        ],
        'marketplace_license' => [
            'label' => 'Marketplace & Lizenz',
            'items' => [['Marketplace', '/admin/marketplace'], ['Lizenzen', '/admin/marketplace/licenses']],
        ],
        'registry_contracts' => [
            'label' => 'Registry',
            'items' => [['Contracts', '/admin/registry']],
        ],
        'update_manager' => [
            'label' => 'Updates',
            'items' => [['Update-Manager', '/admin/updates']],
        ],
        'core_config' => [
            'label' => 'Konfiguration',
            'items' => [['Einstellungen', '/admin/config']],
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
