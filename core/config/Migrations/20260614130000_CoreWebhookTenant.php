<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Tenant-scopes the outbound-webhook tables (E167, consequent multi-tenancy
 * Slice 3b/n, Decision 185).
 *
 * Applies the E165/E166 core-RLS pattern to `webhook_subscriptions` and
 * `webhook_deliveries`. Both are tenant-owned: a subscription belongs to the
 * tenant that created it; a delivery inherits that tenant.
 *
 * Context coverage:
 * - Admin CRUD (`WebhookService` via the admin GUI / `WebhookCommand`) runs in a
 *   tenant context, so reads are scoped and `tenant_id` is captured on insert.
 * - `WebhookService::enqueueForEvent` runs inside the per-event context set by
 *   `OutboxWorker::dispatch`, so it only matches the event tenant's subscriptions
 *   and the new delivery inherits that tenant via the DEFAULT.
 * - `WebhookService::deliverPending` runs in the worker loop OUTSIDE any per-event
 *   context; it is retrofitted (same E167 change) to iterate active tenants and
 *   set each tenant's context before claiming/delivering — so the fail-closed
 *   policy is satisfied without a blanket bypass (the norm prefers per-tenant
 *   iteration for background jobs).
 *
 * `tenant_id` is nullable for the same reason as E166: the WITH CHECK policy is
 * the fail-closed enforcement against the production NOBYPASSRLS role; NOT NULL
 * would be redundant and break superuser-context inserts (tests/maintenance).
 */
class CoreWebhookTenant extends BaseMigration
{
    private const DEFAULT_TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const TABLES = [
        'webhook_subscriptions',
        'webhook_deliveries',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $t) {
            $this->execute("ALTER TABLE core.$t ADD COLUMN tenant_id uuid DEFAULT core.current_tenant()");
            $this->execute(
                "UPDATE core.$t SET tenant_id = '" . self::DEFAULT_TENANT_ID . "' WHERE tenant_id IS NULL",
            );
            $this->execute("CREATE INDEX ix_{$t}_tenant ON core.$t (tenant_id)");

            $this->execute("ALTER TABLE core.$t ENABLE ROW LEVEL SECURITY");
            $this->execute(
                "CREATE POLICY p_{$t}_tenant ON core.$t "
                . 'USING (core.rls_bypass() OR tenant_id = core.current_tenant()) '
                . 'WITH CHECK (core.rls_bypass() OR tenant_id = core.current_tenant())',
            );
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $t) {
            $this->execute("DROP POLICY IF EXISTS p_{$t}_tenant ON core.$t");
            $this->execute("ALTER TABLE core.$t DISABLE ROW LEVEL SECURITY");
            $this->execute("DROP INDEX IF EXISTS core.ix_{$t}_tenant");
            $this->execute("ALTER TABLE core.$t DROP COLUMN IF EXISTS tenant_id");
        }
    }
}
