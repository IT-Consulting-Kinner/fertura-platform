<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Per-tenant backup metadata + admin area (tenant-backup design, Increment 6a).
 *
 * `core.tenant_backups` records the per-tenant data backups a tenant admin creates
 * (the archive of THIS tenant's rows across the tenant-scoped tables) — distinct
 * from the operator-only, full-DB system backups in `core.backups`. RLS
 * tenant-scoped (E165+ pattern), so a tenant only ever sees/manages its own
 * backups; the privileged DR path bypasses via `core.rls_bypass()`.
 *
 * `tenant_backup` is the tenant-facing admin area that gates
 * {@see \App\Controller\Admin\TenantBackupController} (in
 * {@see \App\Controller\Admin\AdminController::TENANT_AREAS}, so it does not cross
 * the operator boundary).
 */
class CoreTenantBackups extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.tenant_backups (
                id           uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                tenant_id    uuid        NOT NULL DEFAULT core.current_tenant(),
                filename     text        NOT NULL,
                storage_path text        NOT NULL,
                bytes        bigint      NOT NULL DEFAULT 0,
                sha256       text        NULL,
                encrypted    boolean     NOT NULL DEFAULT false,
                row_counts   jsonb       NOT NULL DEFAULT '{}',
                note         text        NULL,
                status       text        NOT NULL DEFAULT 'complete',
                created_by   uuid        NULL,
                created_at   timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT ck_tenant_backups_status CHECK (status IN ('complete', 'error')),
                CONSTRAINT fk_tenant_backups_tenant
                    FOREIGN KEY (tenant_id) REFERENCES core.tenants(id) ON DELETE CASCADE
            )
            SQL);
        $this->execute('CREATE INDEX ix_tenant_backups_tenant ON core.tenant_backups (tenant_id, created_at DESC)');

        $this->execute('ALTER TABLE core.tenant_backups ENABLE ROW LEVEL SECURITY');
        $this->execute(
            'CREATE POLICY p_tenant_backups_tenant ON core.tenant_backups '
            . 'USING (core.rls_bypass() OR tenant_id = core.current_tenant()) '
            . 'WITH CHECK (core.rls_bypass() OR tenant_id = core.current_tenant())',
        );

        // Tenant-facing admin area (gates TenantBackupController).
        $this->execute(<<<'SQL'
            INSERT INTO core.admin_areas (area_key, label, sort_order)
            VALUES ('tenant_backup', 'Datensicherung', 30)
            ON CONFLICT (area_key) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        $this->execute("DELETE FROM core.user_admin_areas WHERE admin_area_key = 'tenant_backup'");
        $this->execute("DELETE FROM core.admin_areas WHERE area_key = 'tenant_backup'");
        $this->execute('DROP POLICY IF EXISTS p_tenant_backups_tenant ON core.tenant_backups');
        $this->execute('ALTER TABLE core.tenant_backups DISABLE ROW LEVEL SECURITY');
        $this->execute('DROP TABLE IF EXISTS core.tenant_backups');
    }
}
