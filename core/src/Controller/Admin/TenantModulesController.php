<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditLogger;
use App\Service\Module\TenantModuleService;
use App\Service\Tenant\TenantService;
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
        // The operator/default tenant owns no modules in multi_org mode (operator-
        // tenant design §5b): show the "no modules here" state instead of an
        // un-enableable list. In single_org mode it is still the (only) module user.
        $moduleFree = (new TenantService())->currentTenantIsModuleFree();
        $modules = $moduleFree ? [] : (new TenantModuleService())->listForTenant($this->currentTenantId());
        $this->set(compact('modules', 'moduleFree'));
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
            // The operator/default tenant may not enable modules in multi_org mode
            // (operator-tenant design §5b); the service enforces this too.
            if ($enable && (new TenantService())->currentTenantIsModuleFree()) {
                throw new RuntimeException(__('flash.tenant_modules.operator_no_modules'));
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

    /**
     * Per-tenant module configuration (Increment 5 Phase 3): GET renders a form
     * built from the module's manifest `config_schema` with the tenant's current
     * values; POST coerces the inputs to the declared types and stores them. Only
     * reachable for a module that is enabled for this tenant AND declares a schema.
     */
    public function configure(string $moduleKey): ?Response
    {
        $svc = new TenantModuleService();
        $schema = $svc->configSchema($moduleKey);
        if ($schema === [] || !$svc->isEnabled($moduleKey)) {
            $this->Flash->error(__('flash.tenant_modules.no_config'));

            return $this->redirect(['action' => 'index']);
        }
        if ($this->request->is('post')) {
            $tenantId = $this->currentTenantId();
            try {
                if ($tenantId === '') {
                    throw new RuntimeException(__('flash.tenant_modules.no_tenant'));
                }
                $svc->setConfig($tenantId, $moduleKey, $this->coerceConfig($schema, (array)$this->request->getData()));
                (new AuditLogger())->log('tenant.module.configure', 'tenant_module', null, [
                    'component' => 'core',
                    'moduleKey' => $moduleKey,
                    'newValue' => ['tenant_id' => $tenantId],
                ]);
                $this->Flash->success(__('flash.tenant_modules.configured'));
            } catch (Throwable $e) {
                $this->Flash->error($e->getMessage());
            }

            return $this->redirect(['action' => 'index']);
        }

        // GET: build form fields from the schema (labels/help/options resolved in
        // the contributing module's i18n domain) + the tenant's current values.
        $current = $svc->config($moduleKey);
        $fields = [];
        foreach ($schema as $f) {
            $key = (string)($f['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $type = (string)($f['type'] ?? 'string');
            $spec = [
                'key' => $key,
                'label' => __d($moduleKey, (string)($f['label'] ?? $key)),
                'input' => match ($type) {
                    'bool' => 'checkbox',
                    'int' => 'number',
                    'text' => 'textarea',
                    'select' => 'select',
                    default => 'text',
                },
                'value' => $current[$key] ?? ($f['default'] ?? null),
            ];
            if (isset($f['help'])) {
                $spec['help'] = __d($moduleKey, (string)$f['help']);
            }
            if (!empty($f['required'])) {
                $spec['required'] = true;
            }
            if ($type === 'select' && isset($f['options']) && is_array($f['options'])) {
                $opts = [];
                foreach ($f['options'] as $ov => $ol) {
                    $opts[(string)$ov] = __d($moduleKey, (string)$ol);
                }
                $spec['options'] = $opts;
            }
            $fields[] = $spec;
        }
        $this->set(compact('moduleKey', 'fields'));

        return null;
    }

    /**
     * Coerces posted form values to the types declared in $schema (bool from a
     * checkbox, int, otherwise string); only declared keys are produced. The
     * service additionally drops anything outside the schema.
     *
     * @param list<array<string,mixed>> $schema
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function coerceConfig(array $schema, array $data): array
    {
        $out = [];
        foreach ($schema as $f) {
            $key = (string)($f['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $raw = $data[$key] ?? null;
            $out[$key] = match ((string)($f['type'] ?? 'string')) {
                'bool' => (bool)$raw,
                'int' => (int)$raw,
                default => is_scalar($raw) ? (string)$raw : '',
            };
        }

        return $out;
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
