<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditLogger;
use App\Service\Module\TenantModuleService;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;
use RuntimeException;
use Throwable;

/**
 * Tenant admin GUI "Modules" (operator/tenant authz §5, Increment 5.3): a tenant
 * admin enables/disables the installed (active) modules for THEIR OWN tenant — the
 * consumer surface of the strict opt-in / fail-closed per-tenant enablement
 * ({@see TenantModuleService}).
 *
 * Tenant-scoped area (in {@see AdminController::TENANT_AREAS}, so it does not cross
 * the operator boundary): every action operates on the request's tenant
 * (`core.current_tenant()`), and `core.tenant_modules` is RLS tenant-scoped, so a
 * tenant admin can neither see nor change another tenant's grants. The platform-
 * wide module lifecycle (install/activate/remove) stays operator-only in
 * {@see ModulesController}.
 */
class TenantModulesController extends AdminController
{
    protected ?string $requiredArea = 'tenant_modules';

    public function index(): void
    {
        $modules = (new TenantModuleService())->listForTenant($this->currentTenantId());
        $this->set(compact('modules'));
    }

    public function enable(string $moduleKey): ?Response
    {
        return $this->toggle($moduleKey, true);
    }

    public function disable(string $moduleKey): ?Response
    {
        return $this->toggle($moduleKey, false);
    }

    /** Enables/disables $moduleKey for the current tenant and audits the change. */
    private function toggle(string $moduleKey, bool $enable): ?Response
    {
        $this->request->allowMethod('post');
        $svc = new TenantModuleService();
        $tenantId = $this->currentTenantId();
        try {
            if ($tenantId === '') {
                throw new RuntimeException(__('flash.tenant_modules.no_tenant'));
            }
            // Only an installed, active module may be enabled — guards against a
            // crafted POST enabling a stale/unknown key (disable is always safe).
            if ($enable && !$svc->isActiveModule($moduleKey)) {
                throw new RuntimeException(__('flash.tenant_modules.unknown_module'));
            }
            $enable ? $svc->enable($tenantId, $moduleKey) : $svc->disable($tenantId, $moduleKey);
            (new AuditLogger())->log(
                $enable ? 'tenant.module.enable' : 'tenant.module.disable',
                'tenant_module',
                null,
                [
                    'component' => 'core',
                    'moduleKey' => $moduleKey,
                    'newValue' => ['tenant_id' => $tenantId, 'enabled' => $enable],
                ],
            );
            $this->Flash->success(__($enable ? 'flash.tenant_modules.enabled' : 'flash.tenant_modules.disabled'));
        } catch (Throwable $e) {
            $this->Flash->error($e->getMessage());
        }

        return $this->redirect(['action' => 'index']);
    }

    /** The request's tenant from the RLS context; '' when unset (fail-closed). */
    private function currentTenantId(): string
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        $row = $conn->execute('SELECT core.current_tenant() AS t')->fetch('assoc');

        return $row !== false && $row['t'] !== null ? (string)$row['t'] : '';
    }
}
