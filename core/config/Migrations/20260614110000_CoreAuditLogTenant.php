<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Tenant-scopes `core.audit_log` (E165, consequent multi-tenancy Slice 2/n,
 * Decision 185).
 *
 * audit_log is the revision cornerstone and the FIRST core table to receive
 * database-level RLS: until now DB-RLS only guarded module schemas, while core
 * tables relied on the
 * NOBYPASSRLS app role plus application logic. The context plumbing this depends
 * on is already complete — `TransactionRlsMiddleware` sets the per-request
 * tenant, `TenantIterator` the per-worker tenant (E163), and privileged / CLI
 * paths use the superuser connection (`core.rls_bypass()`).
 *
 * Design:
 * - `tenant_id` is captured automatically via `DEFAULT core.current_tenant()`:
 *   every write records the writer's tenant; system / pre-auth events (no
 *   context) get NULL. No FK to `tenants` — audit is immutable history that must
 *   outlive tenant deletion.
 * - SELECT is fail-closed tenant-scoped, so a tenant admin's audit view (read via
 *   the default connection inside their request context) shows only their own
 *   tenant; operator / cross-tenant readers use the privileged connection.
 * - INSERT stays unconditionally permitted (audit must never fail to record); the
 *   DEFAULT already guarantees correct attribution.
 * - UPDATE/DELETE get no policy and remain blocked by the immutable trigger
 *   (defense in depth: RLS default-deny + the append-only trigger).
 *
 * Partitioned-table note: policies on the partitioned parent are enforced for all
 * access through the parent (`core.audit_log`), so every current and future
 * monthly partition is covered without touching the partition-creation command.
 */
class CoreAuditLogTenant extends BaseMigration
{
    public function up(): void
    {
        // Auto-capture the writer's tenant; NULL for system / pre-auth events.
        $this->execute('ALTER TABLE core.audit_log ADD COLUMN tenant_id uuid DEFAULT core.current_tenant()');
        $this->execute('CREATE INDEX ix_audit_log_tenant ON core.audit_log (tenant_id, created_at DESC)');

        $this->execute('ALTER TABLE core.audit_log ENABLE ROW LEVEL SECURITY');
        // Read: fail-closed tenant scoping (operator/cross-tenant via privileged).
        $this->execute(
            'CREATE POLICY p_audit_log_tenant_read ON core.audit_log '
            . 'FOR SELECT USING (core.rls_bypass() OR tenant_id = core.current_tenant())',
        );
        // Write: never blocked — audit must always record; attribution via DEFAULT.
        $this->execute(
            'CREATE POLICY p_audit_log_insert ON core.audit_log '
            . 'FOR INSERT WITH CHECK (true)',
        );
    }

    public function down(): void
    {
        $this->execute('DROP POLICY IF EXISTS p_audit_log_insert ON core.audit_log');
        $this->execute('DROP POLICY IF EXISTS p_audit_log_tenant_read ON core.audit_log');
        $this->execute('ALTER TABLE core.audit_log DISABLE ROW LEVEL SECURITY');
        $this->execute('DROP INDEX IF EXISTS core.ix_audit_log_tenant');
        $this->execute('ALTER TABLE core.audit_log DROP COLUMN IF EXISTS tenant_id');
    }
}
