<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Inc 7a: a per-tenant backup carries a SCOPE (full / core / a module key) and an
 * optional originating plan. `scope` lets a tenant back up just one module's data
 * (e.g. ticketing) or just the tenant core data; `plan_id` links a scheduled run to
 * its plan (NULL = ad-hoc). The FK to core.tenant_backup_plans is added in Inc 7b
 * (the plans table does not exist yet), so plan_id is a plain nullable uuid here.
 */
class CoreTenantBackupScope extends BaseMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE core.tenant_backups ADD COLUMN scope text NOT NULL DEFAULT 'full'");
        $this->execute('ALTER TABLE core.tenant_backups ADD COLUMN plan_id uuid NULL');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.tenant_backups DROP COLUMN IF EXISTS plan_id');
        $this->execute('ALTER TABLE core.tenant_backups DROP COLUMN IF EXISTS scope');
    }
}
