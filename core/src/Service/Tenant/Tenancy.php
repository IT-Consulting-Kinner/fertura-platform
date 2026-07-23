<?php
declare(strict_types=1);

namespace App\Service\Tenant;

use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;

/**
 * Connection convention for DB-per-tenant (#10/4). Makes the split **central vs.
 * tenant data** explicit and ergonomic — instead of a blanket re-aliasing of
 * `default` (which would break the auth/session bootstrap).
 *
 * - {@see central()} — **central, cross-tenant** tables ALWAYS live on the shared
 *   DB: `users`, `tenants`, `sessions`, `settings`, `audit_log`,
 *   `groups`/`groups_users`, `group_admin_areas`, `sso_providers`, `auth_failures`,
 *   `event_outbox`, license/trust/module tables. Reason (chicken-and-egg):
 *   session/auth/user→tenant must be resolved BEFORE a tenant can be routed.
 * - {@see data()} — **tenant business data** runs on the tenant's own DB when
 *   `db_isolated` (otherwise on the shared one, pool model with RLS/`tenant_id`
 *   scoping). Services/modules for tenant data obtain their connection through
 *   this instead of hard-coding `get('default')`.
 */
final class Tenancy
{
    public static function central(): ConnectionInterface
    {
        return ConnectionManager::get('default');
    }

    public static function data(): ConnectionInterface
    {
        return (new TenantConnectionResolver())->current();
    }
}
