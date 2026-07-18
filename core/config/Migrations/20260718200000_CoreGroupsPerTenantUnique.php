<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Per-tenant group-name uniqueness (review finding on PR #22, HIGH).
 *
 * CoreIdentity created the GLOBAL unique index `uq_groups_name_lower` on
 * lower(name); CoreAuthzTenant later added tenant_id + RLS but never scoped the
 * index. Consequence: the SAME group name cannot exist in two tenants — the
 * bootstrap group "Administratoren" (AdminGroupService::ensure) deterministically
 * crashes with 23505 for every tenant after the first, and a tenant admin cannot
 * create a group whose name another tenant already uses (an information leak on
 * top: the 23505 reveals the foreign name's existence).
 *
 * Replaced by a per-tenant unique index. tenant_id NULL (legacy rows created
 * without tenant context) coalesces to the operator/default tenant — the same
 * convention the SSO/user code applies to NULL tenants.
 */
class CoreGroupsPerTenantUnique extends BaseMigration
{
    /** The operator/default tenant (cf. CoreTenancy). */
    private const DEFAULT_TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function up(): void
    {
        $default = self::DEFAULT_TENANT_ID;
        $this->execute('DROP INDEX IF EXISTS core.uq_groups_name_lower');
        $this->execute(<<<SQL
            CREATE UNIQUE INDEX uq_groups_tenant_name_lower
            ON core."groups" (coalesce(tenant_id, '$default'::uuid), lower(name))
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS core.uq_groups_tenant_name_lower');
        $this->execute('CREATE UNIQUE INDEX uq_groups_name_lower ON core."groups" (lower(name))');
    }
}
