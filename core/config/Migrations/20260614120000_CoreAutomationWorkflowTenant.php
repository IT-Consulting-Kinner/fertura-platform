<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Tenant-scopes the automation / workflow / notification data tables (E166,
 * consequent multi-tenancy Slice 3a/n, Decision 185).
 *
 * Reuses the E165 core-RLS pattern (DEFAULT-capture + fail-closed policy) on the
 * five tenant-owned data tables driven by the event pipeline:
 * `automation_rules`, `workflow_definitions`, `workflow_instances`,
 * `notifications`, `notification_prefs`.
 *
 * Why these five and why now: their read/write paths all already run inside a
 * tenant context — requests via `TransactionRlsMiddleware`, and the background
 * paths via `OutboxWorker::dispatch()`, which already sets
 * `app.current_tenant_id` from each event's `tenant_id` before invoking
 * `AutomationEngine` / `WorkflowEngine` / notification actions. So scoping these
 * tables makes the engines tenant-correct automatically (an event for tenant B
 * sees only tenant B's rules). Webhooks are intentionally NOT here — their
 * delivery (`WebhookService::deliverPending`) runs OUTSIDE the per-event context
 * and needs its own per-tenant iteration (separate slice).
 *
 * Unlike audit_log these are normal CRUD tables, so the policy is a single
 * `FOR ALL` policy with both USING (read/update/delete) and WITH CHECK (insert/
 * update) — the latter makes a context-less write fail-closed (rejected) rather
 * than silently mis-attributed.
 *
 * Existing single-org rows are backfilled to the default tenant. `tenant_id` is
 * left nullable deliberately: the fail-closed enforcement is the WITH CHECK
 * policy (a context-less write is rejected against the production NOBYPASSRLS
 * role), so a NOT NULL constraint would be redundant there while needlessly
 * breaking superuser-context inserts (tests, maintenance) that legitimately
 * bypass RLS. The two console writers (`AutomationCommand`, `WorkflowCommand`)
 * are retrofitted to set the tenant context via `CliTenantContext` so CLI-created
 * rules carry the correct tenant.
 */
class CoreAutomationWorkflowTenant extends BaseMigration
{
    private const DEFAULT_TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const TABLES = [
        'automation_rules',
        'workflow_definitions',
        'workflow_instances',
        'notifications',
        'notification_prefs',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $t) {
            // Auto-capture the writer's tenant from the RLS context.
            $this->execute("ALTER TABLE core.$t ADD COLUMN tenant_id uuid DEFAULT core.current_tenant()");
            // Existing single-org rows belong to the default tenant.
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
