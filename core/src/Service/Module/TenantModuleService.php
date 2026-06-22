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
     * The installed (active) modules plus whether each is enabled for $tenantId —
     * the row model for the tenant-facing enable/disable GUI (Increment 5.3). A
     * module the tenant has never configured appears with enabled=false (fail-closed).
     *
     * @return list<array{module_key:string, name:string, version:string, enabled:bool, has_config:bool}>
     */
    public function listForTenant(string $tenantId): array
    {
        $rows = $this->conn()->execute(
            'SELECT m.module_key, m.name, m.version, COALESCE(tm.enabled, false) AS enabled, '
            . "CASE WHEN jsonb_typeof(m.manifest->'config_schema') = 'array' "
            . "AND jsonb_array_length(m.manifest->'config_schema') > 0 THEN true ELSE false END AS has_config "
            . 'FROM modules m '
            . 'LEFT JOIN tenant_modules tm ON tm.module_key = m.module_key AND tm.tenant_id = :t '
            . "WHERE m.status = 'active' "
            . 'ORDER BY m.name',
            ['t' => $tenantId],
        )->fetchAll('assoc');

        return array_values(array_map(static fn(array $r): array => [
            'module_key' => (string)$r['module_key'],
            'name' => (string)$r['name'],
            'version' => (string)$r['version'],
            'enabled' => $r['enabled'] === true || $r['enabled'] === 't',
            'has_config' => $r['has_config'] === true || $r['has_config'] === 't',
        ], $rows));
    }

    /** Whether $moduleKey is an installed, active module (guards enable against stale keys). */
    public function isActiveModule(string $moduleKey): bool
    {
        $row = $this->conn()->execute(
            "SELECT EXISTS (SELECT 1 FROM modules WHERE module_key = :k AND status = 'active') AS ok",
            ['k' => $moduleKey],
        )->fetch('assoc');

        return $row !== false && ($row['ok'] === true || $row['ok'] === 't');
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

    /**
     * The per-tenant config-field schema the (active) module declares in its
     * manifest (Increment 5 Phase 3); empty list if it declares none. Read from the
     * stored `modules.manifest` jsonb, so no module file access is needed.
     *
     * @return list<array<string, mixed>>
     */
    public function configSchema(string $moduleKey): array
    {
        $row = $this->conn()->execute(
            "SELECT manifest FROM modules WHERE module_key = :k AND status = 'active'",
            ['k' => $moduleKey],
        )->fetch('assoc');
        if ($row === false || !is_string($row['manifest'])) {
            return [];
        }

        return (new ModuleManifest((array)(json_decode($row['manifest'], true) ?: [])))->configSchema();
    }

    /**
     * The module's stored per-tenant config (the `tenant_modules.config` jsonb) for
     * $tenantId, or — when '' — the current request tenant. Empty array when there
     * is no grant row (or no tenant context). This is what the Core injects as
     * `module_config` into a module's web/API request and listener context.
     *
     * @return array<string, mixed>
     */
    public function config(string $moduleKey, string $tenantId = ''): array
    {
        $tenantExpr = $tenantId === '' ? 'core.current_tenant()' : ':t';
        $params = ['k' => $moduleKey];
        if ($tenantId !== '') {
            $params['t'] = $tenantId;
        }
        $row = $this->conn()->execute(
            "SELECT config FROM tenant_modules WHERE module_key = :k AND tenant_id = $tenantExpr",
            $params,
        )->fetch('assoc');
        if ($row === false || !is_string($row['config'])) {
            return [];
        }
        $decoded = json_decode($row['config'], true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Replaces the stored per-tenant config for a module, keeping ONLY keys the
     * module declares in its config_schema (unknown keys are dropped — defence
     * against a crafted POST). A no-op when the tenant has no grant row (enable
     * first). The caller (the tenant GUI) coerces form values to the declared types
     * and audits the change.
     *
     * @param array<string, mixed> $config
     */
    public function setConfig(string $tenantId, string $moduleKey, array $config): void
    {
        $allowed = array_map(
            static fn(array $f): string => (string)($f['key'] ?? ''),
            $this->configSchema($moduleKey),
        );
        $filtered = array_intersect_key($config, array_fill_keys($allowed, true));
        $this->conn()->execute(
            'UPDATE tenant_modules SET config = CAST(:c AS jsonb) WHERE tenant_id = :t AND module_key = :k',
            ['c' => json_encode($filtered) ?: '{}', 't' => $tenantId, 'k' => $moduleKey],
        );
    }
}
