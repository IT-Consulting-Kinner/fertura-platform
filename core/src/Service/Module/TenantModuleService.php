<?php
declare(strict_types=1);

namespace App\Service\Module;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * Per-tenant module enablement (operator/tenant authz design §5, Increment 5).
 *
 * The counterpart to the operator-owned, platform-wide module lifecycle
 * ({@see ModuleLifecycle}): the lifecycle decides which modules are *available*
 * on the platform (`core.modules.status = 'active'`), this service decides which
 * of those a given TENANT may actually *use* — the strict opt-in / fail-closed
 * model (see {@see \App\Controller\ModuleWebController} and
 * {@see WebRouteRegistry::adminNav} for the gates that consume it).
 *
 * All read methods are inherently scoped to the request's tenant via
 * `core.current_tenant()` and additionally constrain `tenant_id` in the query, so
 * they are correct both under the production NOBYPASSRLS role (where the RLS
 * policy also scopes the row) and under the RLS-bypassing owner/superuser role
 * the test-suite runs as (where only the explicit predicate scopes it).
 */
class TenantModuleService
{
    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /**
     * Whether $moduleKey is enabled for the current request tenant. Fail-closed:
     * no grant row (or a disabled one, or no tenant context) returns false.
     */
    public function isEnabled(string $moduleKey): bool
    {
        $row = $this->conn()->execute(
            'SELECT EXISTS (SELECT 1 FROM tenant_modules '
            . 'WHERE module_key = :k AND tenant_id = core.current_tenant() AND enabled) AS ok',
            ['k' => $moduleKey],
        )->fetch('assoc');

        return $row !== false && (bool)$row['ok'];
    }

    /**
     * The module keys enabled for the current request tenant — the allow-list the
     * admin-nav gate filters module-contributed entries against.
     *
     * @return list<string>
     */
    public function enabledKeys(): array
    {
        $rows = $this->conn()->execute(
            'SELECT module_key FROM tenant_modules '
            . 'WHERE tenant_id = core.current_tenant() AND enabled',
        )->fetchAll('assoc');

        return array_values(array_map(static fn($r): string => (string)$r['module_key'], $rows));
    }

    /**
     * Grants (enables) $moduleKey for $tenantId — idempotent upsert. A previously
     * disabled grant is re-enabled while keeping its stored `config`.
     *
     * Under the production NOBYPASSRLS role this only succeeds for the caller's own
     * tenant (the RLS WITH CHECK); cross-tenant provisioning runs on the privileged
     * connection.
     */
    public function enable(string $tenantId, string $moduleKey): void
    {
        $this->conn()->execute(
            'INSERT INTO tenant_modules (tenant_id, module_key, enabled) VALUES (:t, :k, true) '
            . 'ON CONFLICT (tenant_id, module_key) DO UPDATE SET enabled = true',
            ['t' => $tenantId, 'k' => $moduleKey],
        );
    }

    /**
     * Revokes (disables) $moduleKey for $tenantId. Keeps the row (and its `config`)
     * so a later re-enable preserves the tenant's configuration; a missing row is a
     * no-op (already fail-closed = not enabled).
     */
    public function disable(string $tenantId, string $moduleKey): void
    {
        $this->conn()->execute(
            'UPDATE tenant_modules SET enabled = false WHERE tenant_id = :t AND module_key = :k',
            ['t' => $tenantId, 'k' => $moduleKey],
        );
    }
}
