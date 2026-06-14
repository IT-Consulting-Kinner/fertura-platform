<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Tenant-scopes `core.sso_providers` (E171, consequent multi-tenancy Slice 4b/n,
 * Decision 185).
 *
 * sso_providers carried neither `user_id` nor `tenant_id`, so SSO configuration
 * was GLOBAL across tenants — a real isolation gap (every tenant configures its
 * own identity provider, client id/secret). This adds `tenant_id`.
 *
 * **Deliberately NO RLS policy:** sso_providers is a PRE-AUTH table — the login
 * page lists active providers before any user (hence any user/group context)
 * exists. A fail-closed policy would hide all providers whenever the tenant
 * context is unset (e.g. single-org, where the host maps to no tenant) and break
 * SSO login. Per Decision 185 pre-auth tables carry `tenant_id` without a blocking
 * policy; the isolation is an application filter in `SsoService` (login page +
 * admin listing filter by `coalesce(core.current_tenant(), <default tenant>)`),
 * the tenant coming from the host resolver pre-auth.
 *
 * Existing single-org rows are backfilled to the default tenant; new rows capture
 * the admin's tenant via the column DEFAULT.
 */
class CoreSsoProviderTenant extends BaseMigration
{
    private const DEFAULT_TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function up(): void
    {
        $this->execute('ALTER TABLE core.sso_providers ADD COLUMN tenant_id uuid DEFAULT core.current_tenant()');
        $this->execute(
            "UPDATE core.sso_providers SET tenant_id = '" . self::DEFAULT_TENANT_ID . "' WHERE tenant_id IS NULL",
        );
        $this->execute('CREATE INDEX ix_sso_providers_tenant ON core.sso_providers (tenant_id)');
        // No ENABLE ROW LEVEL SECURITY / policy on purpose — see the class docblock
        // (pre-auth read path); isolation is the SsoService application filter.
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS core.ix_sso_providers_tenant');
        $this->execute('ALTER TABLE core.sso_providers DROP COLUMN IF EXISTS tenant_id');
    }
}
