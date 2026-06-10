<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Tenant\TenantService;
use Cake\Datasource\ConnectionManager;
use RuntimeException;
use Throwable;

/**
 * Admin-GUI „Mandanten" (Multi-Tenancy, Bereich Core-Konfiguration): Übersicht
 * + Anlage. Nutzt das Modul-UI-Kit (sortierbare Köpfe, Paginierung, Formularfelder)
 * und ist zugleich der erste echte Screen, der das Kit adoptiert.
 */
class TenantsController extends AdminController
{
    protected ?string $requiredArea = 'core_config';

    private const PER_PAGE = 20;
    private const SORTABLE = ['name', 'key', 'active'];

    public function index(): void
    {
        $tenants = (new TenantService())->all();

        $sort = (string)$this->request->getQuery('sort', 'name');
        if (!in_array($sort, self::SORTABLE, true)) {
            $sort = 'name';
        }
        $dir = strtolower((string)$this->request->getQuery('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        usort($tenants, static function (array $a, array $b) use ($sort, $dir): int {
            $cmp = strcmp((string)$a[$sort], (string)$b[$sort]);

            return $dir === 'asc' ? $cmp : -$cmp;
        });

        $total = count($tenants);
        // Vollständige (aktive) Liste für die Zuweisungs-Auswahl, vor dem Slicen.
        $allTenants = [];
        foreach ($tenants as $t) {
            if ($t['active']) {
                $allTenants[$t['id']] = $t['name'];
            }
        }
        $page = max(1, (int)$this->request->getQuery('page', 1));
        $tenants = array_slice($tenants, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $this->set(compact('tenants', 'allTenants', 'sort', 'dir', 'page', 'total'));
        $this->set('perPage', self::PER_PAGE);
        $this->set('query', $this->request->getQueryParams());
    }

    /** Ordnet einen Benutzer (per E-Mail) einem Mandanten zu. */
    public function assign()
    {
        $this->request->allowMethod('post');
        $email = trim((string)$this->request->getData('email'));
        $tenantId = (string)$this->request->getData('tenant_id');
        try {
            $user = ConnectionManager::get('default')->execute(
                'SELECT id FROM users WHERE lower(email) = lower(:e)',
                ['e' => $email],
            )->fetch('assoc');
            if ($user === false) {
                throw new RuntimeException(__('flash.tenants.user_not_found'));
            }
            (new TenantService())->assignUser((string)$user['id'], $tenantId);
            $this->Flash->success(__('flash.tenants.assigned'));
        } catch (Throwable $e) {
            $this->Flash->error($e->getMessage());
        }

        return $this->redirect(['action' => 'index']);
    }

    public function add()
    {
        $this->request->allowMethod('post');
        try {
            (new TenantService())->create(
                (string)$this->request->getData('key'),
                (string)$this->request->getData('name'),
            );
            $this->Flash->success(__('flash.tenants.created'));
        } catch (Throwable $e) {
            $this->Flash->error($e->getMessage());
        }

        return $this->redirect(['action' => 'index']);
    }

    /** Sammelaktion: ausgewählte Mandanten aktivieren oder suspendieren. */
    public function bulk()
    {
        $this->request->allowMethod('post');
        $op = (string)$this->request->getData('op');
        $ids = array_values(array_filter((array)$this->request->getData('ids')));
        $svc = new TenantService();
        $n = 0;
        foreach ($ids as $id) {
            try {
                $svc->setActive((string)$id, $op === 'activate');
                $n++;
            } catch (Throwable) {
            }
        }
        $this->Flash->success(__('flash.tenants.bulk_done', $n));

        return $this->redirect(['action' => 'index']);
    }
}
