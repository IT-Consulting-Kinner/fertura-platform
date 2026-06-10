<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Tenant\TenantService;
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
        $page = max(1, (int)$this->request->getQuery('page', 1));
        $tenants = array_slice($tenants, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $this->set(compact('tenants', 'sort', 'dir', 'page', 'total'));
        $this->set('perPage', self::PER_PAGE);
        $this->set('query', $this->request->getQueryParams());
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
}
