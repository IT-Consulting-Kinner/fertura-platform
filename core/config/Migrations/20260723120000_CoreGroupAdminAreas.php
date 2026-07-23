<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Moves ADMIN-AREA grants from per-USER to per-GROUP (authorization model change).
 *
 * Until now an administration area (`core.admin_areas`) was granted to individual
 * users via `core.user_admin_areas`. That table carried no `tenant_id` and no RLS
 * — the same cross-tenant gap `CoreAuthzTenant` closed for the group model. This
 * introduces `core.group_admin_areas` as the new grant table, owned by a GROUP and
 * tenant-scoped WITH RLS (same pattern as `group_resource_permissions`), and drops
 * `user_admin_areas`.
 *
 * The catalog `admin_areas` stays GLOBAL (a module-contributed area is platform
 * metadata, not tenant data) — only the grant table changes owner user -> group.
 *
 * The "Administrators" (is_system) group ALWAYS holds every area — that is enforced
 * as a VIRTUAL wildcard at check time (a member of an is_system group resolves to
 * ALL `admin_areas`), so it needs no rows here and never drifts when a module adds
 * a new area. Non-system groups hold an explicit subset of rows in this table.
 *
 * Pre-release change: existing per-user grants are NOT migrated (there is no
 * released data to preserve). `create_admin` re-establishes admin access by group
 * membership (the is_system group's wildcard).
 *
 * `tenant_id` is nullable with a `core.current_tenant()` default — the WITH CHECK
 * policy is the fail-closed enforcement against the NOBYPASSRLS app role, exactly
 * as in {@see CoreAuthzTenant}.
 */
class CoreGroupAdminAreas extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.group_admin_areas (
                id             uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                group_id       uuid        NOT NULL,
                admin_area_key text        NOT NULL,
                tenant_id      uuid        DEFAULT core.current_tenant(),
                created_by     uuid        NULL,
                created_at     timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fk_group_admin_areas_group
                    FOREIGN KEY (group_id) REFERENCES core."groups"(id) ON DELETE CASCADE,
                CONSTRAINT fk_group_admin_areas_area
                    FOREIGN KEY (admin_area_key) REFERENCES core.admin_areas(area_key) ON DELETE RESTRICT,
                CONSTRAINT fk_group_admin_areas_created_by
                    FOREIGN KEY (created_by) REFERENCES core.users(id) ON DELETE SET NULL,
                CONSTRAINT uq_group_admin_areas UNIQUE (group_id, admin_area_key)
            )
            SQL);
        $this->execute('CREATE INDEX ix_group_admin_areas_group ON core.group_admin_areas (group_id)');
        $this->execute('CREATE INDEX ix_group_admin_areas_tenant ON core.group_admin_areas (tenant_id)');

        $this->execute('ALTER TABLE core.group_admin_areas ENABLE ROW LEVEL SECURITY');
        $this->execute(
            'CREATE POLICY p_group_admin_areas_tenant ON core.group_admin_areas '
            . 'USING (core.rls_bypass() OR tenant_id = core.current_tenant()) '
            . 'WITH CHECK (core.rls_bypass() OR tenant_id = core.current_tenant())',
        );

        // The old per-user grant table is replaced wholesale (no released data).
        $this->execute('DROP TABLE core.user_admin_areas');
    }

    public function down(): void
    {
        // Recreate the per-user grant table as it was in CoreIdentity (empty — the
        // pre-migration grants are not recoverable from the group model).
        $this->execute(<<<'SQL'
            CREATE TABLE core.user_admin_areas (
                id             uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                user_id        uuid        NOT NULL,
                admin_area_key text        NOT NULL,
                created_by     uuid        NULL,
                created_at     timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fk_user_admin_areas_user
                    FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_admin_areas_area
                    FOREIGN KEY (admin_area_key) REFERENCES core.admin_areas(area_key) ON DELETE RESTRICT,
                CONSTRAINT fk_user_admin_areas_created_by
                    FOREIGN KEY (created_by) REFERENCES core.users(id) ON DELETE SET NULL,
                CONSTRAINT uq_user_admin_areas UNIQUE (user_id, admin_area_key)
            )
            SQL);
        $this->execute('CREATE INDEX ix_user_admin_areas_user ON core.user_admin_areas (user_id)');

        $this->execute('DROP POLICY IF EXISTS p_group_admin_areas_tenant ON core.group_admin_areas');
        $this->execute('DROP TABLE core.group_admin_areas');
    }
}
