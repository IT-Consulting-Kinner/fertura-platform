<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditLogger;
use App\Model\Entity\User;
use App\Service\Identity\PasswordResetService;
use App\Service\Mail\MailService;
use App\Service\Tenant\TenantService;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;
use RuntimeException;
use Throwable;

/**
 * Admin GUI "Tenants" (multi-tenancy, Core Configuration area): overview
 * + creation. Uses the module UI kit (sortable headers, pagination, form fields)
 * and is also the first real screen to adopt the kit.
 */
class TenantsController extends AdminController
{
    protected ?string $requiredArea = 'core_config';

    private const PER_PAGE = 20;
    private const SORTABLE = ['name', 'key', 'active'];

    public function index(): void
    {
        $this->renderList(false);
    }

    /** Renders the tenant list + the inline "create" accordion form. */
    private function renderList(bool $openCreate): void
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
        // Full (active) list for the assignment selector, captured before slicing.
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
        $this->set('openCreate', $openCreate);
        $this->viewBuilder()->setTemplate('index');
    }

    /** Assigns a user (by email) to a tenant. */
    public function assign(): ?Response
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

    public function add(): ?Response
    {
        $this->request->allowMethod('post');
        try {
            (new TenantService())->create(
                (string)$this->request->getData('key'),
                (string)$this->request->getData('name'),
                $this->request->getData('brand_name') !== null ? (string)$this->request->getData('brand_name') : null,
                $this->request->getData('logo_url') !== null ? (string)$this->request->getData('logo_url') : null,
            );
            $this->Flash->success(__('flash.tenants.created'));

            return $this->redirect(['action' => 'index']);
        } catch (Throwable $e) {
            // Re-render the list with the create accordion open and the input kept.
            $this->Flash->error($e->getMessage());
            $this->renderList(true);

            return null;
        }
    }

    /**
     * Onboarding step (operator-tenant design §7, decided: a SEPARATE step after
     * provisioning): creates the first/another ADMIN for a tenant — a user IN the
     * target tenant, granted the tenant user/group-admin area, plus an invitation
     * link. Operator-only (core_config, gate-enforced). Cross-tenant by design: the
     * operator creates a user whose tenant differs from the operator's own RLS context
     * (`users`/`user_admin_areas` carry no RLS, so the writes are not blocked). The
     * operator tenant itself is refused here — its admins are operator-admins, managed
     * separately, not onboarded as tenant admins.
     */
    public function createAdmin(): ?Response
    {
        $this->request->allowMethod('post');
        $tenantId = (string)$this->request->getData('tenant_id');
        $email = trim((string)$this->request->getData('email'));
        $username = trim((string)$this->request->getData('username'));
        try {
            if (!$this->isUuid($tenantId) || $tenantId === self::OPERATOR_TENANT_ID) {
                throw new RuntimeException(__('flash.tenants.admin_invalid_tenant'));
            }
            if ((new TenantService())->get($tenantId) === null) {
                throw new RuntimeException(__('flash.tenants.admin_invalid_tenant'));
            }
            if ($email === '' || $username === '') {
                throw new RuntimeException(__('flash.tenants.admin_params'));
            }
            $users = $this->fetchTable('Users');
            /** @var \App\Model\Entity\User $user */
            $user = $users->patchEntity($users->newEmptyEntity(), ['username' => $username, 'email' => $email]);
            $user->set('status', User::STATUS_INVITED);
            $user->set('tenant_id', $tenantId); // the TARGET tenant, not the operator's
            if (!$users->save($user)) {
                throw new RuntimeException(__('flash.user.create_failed'));
            }
            $userId = (string)$user->id;
            // Grant the tenant user/group-admin area (held within the new tenant).
            /** @var \Cake\Database\Connection $conn */
            $conn = ConnectionManager::get('default');
            $conn->execute(
                'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a) ON CONFLICT DO NOTHING',
                ['u' => $userId, 'a' => 'user_group_admin'],
            );
            // Invitation / password-set link (like UsersController::invite).
            $actor = $this->identity()?->getIdentifier();
            $token = (new PasswordResetService())->create($userId, 'invite', 72, is_scalar($actor) ? (string)$actor : null);
            $url = (string)$this->request->getUri()->withPath('/set-password')->withQuery('token=' . $token);
            (new MailService())->sendInvitation($email, $username, $url);
            (new AuditLogger())->log('tenant.create_admin', 'user', $userId, [
                'component' => 'core',
                'newValue' => ['tenant_id' => $tenantId],
            ]);
            $this->Flash->success(__('flash.tenants.admin_created', $url));
        } catch (Throwable $e) {
            $this->Flash->error($e->getMessage());
        }

        return $this->redirect(['action' => 'index']);
    }

    /** Bulk action: activate or suspend the selected tenants. */
    public function bulk(): ?Response
    {
        $this->request->allowMethod('post');
        $op = (string)$this->request->getData('op');
        $ids = array_values(array_filter((array)$this->request->getData('ids')));
        $svc = new TenantService();
        $n = 0;
        $errors = 0;
        foreach ($ids as $id) {
            try {
                if ($op === 'delete') {
                    $svc->delete((string)$id);
                } else {
                    $svc->setActive((string)$id, $op === 'activate');
                }
                $n++;
            } catch (Throwable) {
                $errors++;
            }
        }
        $this->Flash->success(__('flash.tenants.bulk_done', $n));
        if ($errors > 0) {
            $this->Flash->error(__('flash.tenants.bulk_errors', $errors));
        }

        return $this->redirect(['action' => 'index']);
    }
}
