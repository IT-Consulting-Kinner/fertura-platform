<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Inc 7b: per-tenant backup PLANS — a tenant admin schedules recurring scoped
 * backups (e.g. ticketing daily, knowledgebase weekly). RLS tenant-scoped like
 * core.tenant_backups; the scheduler (Inc 7c) runs due plans under each tenant's
 * RLS context. Also wires the FK from core.tenant_backups.plan_id (added as a
 * plain uuid in Inc 7a) to this table, ON DELETE SET NULL so removing a plan keeps
 * its already-created backups (just unlinks them).
 */
class CoreTenantBackupPlans extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.tenant_backup_plans (
                id             uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                tenant_id      uuid        NOT NULL DEFAULT core.current_tenant(),
                name           text        NOT NULL,
                scope          text        NOT NULL DEFAULT 'full',
                cadence        text        NOT NULL DEFAULT 'daily',
                hour           int         NOT NULL DEFAULT 2,
                weekday        int         NULL,
                day_of_month   int         NULL,
                retention_keep int         NOT NULL DEFAULT 7,
                active         boolean     NOT NULL DEFAULT true,
                last_run_at    timestamptz NULL,
                next_run_at    timestamptz NULL,
                created_at     timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT ck_tbp_cadence CHECK (cadence IN ('daily', 'weekly', 'monthly')),
                CONSTRAINT ck_tbp_hour CHECK (hour BETWEEN 0 AND 23),
                CONSTRAINT ck_tbp_weekday CHECK (weekday IS NULL OR weekday BETWEEN 0 AND 6),
                CONSTRAINT ck_tbp_day CHECK (day_of_month IS NULL OR day_of_month BETWEEN 1 AND 28),
                CONSTRAINT ck_tbp_retention CHECK (retention_keep BETWEEN 1 AND 365),
                CONSTRAINT fk_tbp_tenant FOREIGN KEY (tenant_id) REFERENCES core.tenants(id) ON DELETE CASCADE
            )
            SQL);
        $this->execute('CREATE INDEX ix_tbp_tenant ON core.tenant_backup_plans (tenant_id)');
        $this->execute('CREATE INDEX ix_tbp_due ON core.tenant_backup_plans (active, next_run_at)');

        $this->execute('ALTER TABLE core.tenant_backup_plans ENABLE ROW LEVEL SECURITY');
        $this->execute(
            'CREATE POLICY p_tbp_tenant ON core.tenant_backup_plans '
            . 'USING (core.rls_bypass() OR tenant_id = core.current_tenant()) '
            . 'WITH CHECK (core.rls_bypass() OR tenant_id = core.current_tenant())',
        );

        $this->execute(
            'ALTER TABLE core.tenant_backups ADD CONSTRAINT fk_tenant_backups_plan '
            . 'FOREIGN KEY (plan_id) REFERENCES core.tenant_backup_plans(id) ON DELETE SET NULL',
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.tenant_backups DROP CONSTRAINT IF EXISTS fk_tenant_backups_plan');
        $this->execute('DROP POLICY IF EXISTS p_tbp_tenant ON core.tenant_backup_plans');
        $this->execute('ALTER TABLE core.tenant_backup_plans DISABLE ROW LEVEL SECURITY');
        $this->execute('DROP TABLE IF EXISTS core.tenant_backup_plans');
    }
}
