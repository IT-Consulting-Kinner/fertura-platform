<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Tenant-scopes the group/authorization model (E170, consequent multi-tenancy
 * Slice 4a/n, Decision 185) — the security-critical core of the hardening.
 *
 * Until now `groups` carried no `tenant_id`, so groups (and the permissions
 * attached to them) were GLOBAL across tenants — a real authz tenant gap. This
 * scopes the three authz tables so each tenant's groups, memberships and BREAD
 * permissions are isolated:
 *   - `groups`                      — a tenant's user groups
 *   - `groups_users`                — membership (tenant = the group's/user's)
 *   - `group_resource_permissions`  — per-group BREAD grants
 *
 * `resources` stays central (E169): it is a global module-resource catalog, not
 * tenant data.
 *
 * **Critical ordering dependency:** `core.current_group_ids()` (fed from
 * `groups_users`) is consumed by EVERY module RLS policy. The request resolves a
 * user's groups via `PermissionService::activeGroupIds`, which previously ran in
 * `TransactionRlsMiddleware` BEFORE the tenant context was applied. With these
 * fail-closed policies that read would return nothing → every user locked out.
 * The middleware is reordered (same E170 change) to set the tenant context FIRST
 * (from the authenticated user's tenant, read from the policy-free `users` table),
 * then resolve groups within that tenant. Authenticated users always have a tenant
 * (`users.tenant_id` is NOT NULL DEFAULT default tenant), so there is no lockout.
 *
 * `tenant_id` is nullable for the same reason as E166/E167: the WITH CHECK policy
 * is the fail-closed enforcement against the production NOBYPASSRLS role.
 */
class CoreAuthzTenant extends BaseMigration
{
    private const DEFAULT_TENANT_ID = '00000000-0000-0000-0000-000000000001';

    /** @var array<array{0:string,1:string}> [quoted table identifier, short name for index/policy] */
    private const TABLES = [
        ['core."groups"', 'groups'],
        ['core.groups_users', 'groups_users'],
        ['core.group_resource_permissions', 'group_resource_permissions'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as [$ident, $short]) {
            $this->execute("ALTER TABLE $ident ADD COLUMN tenant_id uuid DEFAULT core.current_tenant()");
            // Existing single-org rows belong to the default tenant.
            $this->execute("UPDATE $ident SET tenant_id = '" . self::DEFAULT_TENANT_ID . "' WHERE tenant_id IS NULL");
            $this->execute("CREATE INDEX ix_{$short}_tenant ON $ident (tenant_id)");

            $this->execute("ALTER TABLE $ident ENABLE ROW LEVEL SECURITY");
            $this->execute(
                "CREATE POLICY p_{$short}_tenant ON $ident "
                . 'USING (core.rls_bypass() OR tenant_id = core.current_tenant()) '
                . 'WITH CHECK (core.rls_bypass() OR tenant_id = core.current_tenant())',
            );
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as [$ident, $short]) {
            $this->execute("DROP POLICY IF EXISTS p_{$short}_tenant ON $ident");
            $this->execute("ALTER TABLE $ident DISABLE ROW LEVEL SECURITY");
            $this->execute("DROP INDEX IF EXISTS core.ix_{$short}_tenant");
            $this->execute("ALTER TABLE $ident DROP COLUMN IF EXISTS tenant_id");
        }
    }
}
