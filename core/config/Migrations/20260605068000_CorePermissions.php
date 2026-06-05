<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * BREAD-Berechtigungen + RLS-Infrastruktur (Step 9, Kap. 25 / 27.6–27.9 / 30.3).
 *
 * - resources: von Modulen deklarierte, gruppenfähige Ressourcen.
 * - group_resource_permissions: Gruppe → Ressource → BREAD + Zusatzaktionen
 *   (rein additiv, keine Deny-Regeln, Entscheidung 124).
 * - RLS-Helfer-Funktionen, die Module in ihren Policy-Prädikaten nutzen
 *   (Zugriffskontext via SET LOCAL, Entscheidung 175).
 */
class CorePermissions extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.resources (
                id            uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                module_key    text        NOT NULL,
                resource_type text        NOT NULL,
                resource_name text        NOT NULL,
                description   text        NULL,
                is_scoped     boolean     NOT NULL DEFAULT false,
                extra_actions jsonb       NULL,
                created_at    timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_resources UNIQUE (module_key, resource_name)
            )
            SQL);
        $this->execute('CREATE INDEX ix_resources_module ON core.resources (module_key)');

        $this->execute(<<<'SQL'
            CREATE TABLE core.group_resource_permissions (
                id            uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                group_id      uuid        NOT NULL,
                module_key    text        NOT NULL,
                resource_type text        NOT NULL,
                resource_key  text        NULL,
                can_browse    boolean     NOT NULL DEFAULT false,
                can_read      boolean     NOT NULL DEFAULT false,
                can_add       boolean     NOT NULL DEFAULT false,
                can_edit      boolean     NOT NULL DEFAULT false,
                can_delete    boolean     NOT NULL DEFAULT false,
                extra_actions jsonb       NULL,
                created_by    uuid        NULL,
                updated_by    uuid        NULL,
                created_at    timestamptz NOT NULL DEFAULT now(),
                updated_at    timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fk_grp FOREIGN KEY (group_id) REFERENCES core."groups"(id) ON DELETE CASCADE,
                CONSTRAINT fk_grp_created_by FOREIGN KEY (created_by) REFERENCES core.users(id) ON DELETE SET NULL,
                CONSTRAINT fk_grp_updated_by FOREIGN KEY (updated_by) REFERENCES core.users(id) ON DELETE SET NULL
            )
            SQL);
        // resource_key NULL = Objektklasse; eindeutig je (Gruppe, Modul, Typ, Schlüssel).
        $this->execute(
            'CREATE UNIQUE INDEX uq_grp_perm ON core.group_resource_permissions '
            . "(group_id, module_key, resource_type, coalesce(resource_key, '*'))"
        );
        $this->execute('CREATE INDEX ix_grp_perm_lookup ON core.group_resource_permissions (module_key, resource_type, group_id)');
        $this->execute(
            'CREATE TRIGGER trg_grp_perm_set_updated_at BEFORE UPDATE ON core.group_resource_permissions '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()'
        );

        // ---- RLS-Helfer (von Modul-Policies genutzt) ------------------------
        // Zugriffskontext wird pro Transaktion via SET LOCAL gesetzt:
        //   app.current_user_id, app.current_group_ids (CSV uuids), app.bypass_rls
        $this->execute(<<<'SQL'
            CREATE OR REPLACE FUNCTION core.current_user_id()
            RETURNS uuid LANGUAGE sql STABLE AS $func$
                SELECT nullif(current_setting('app.current_user_id', true), '')::uuid
            $func$
            SQL);
        $this->execute(<<<'SQL'
            CREATE OR REPLACE FUNCTION core.current_group_ids()
            RETURNS uuid[] LANGUAGE sql STABLE AS $func$
                SELECT CASE
                    WHEN coalesce(current_setting('app.current_group_ids', true), '') = '' THEN ARRAY[]::uuid[]
                    ELSE string_to_array(current_setting('app.current_group_ids', true), ',')::uuid[]
                END
            $func$
            SQL);
        $this->execute(<<<'SQL'
            CREATE OR REPLACE FUNCTION core.rls_bypass()
            RETURNS boolean LANGUAGE sql STABLE AS $func$
                SELECT coalesce(nullif(current_setting('app.bypass_rls', true), ''), 'false')::boolean
            $func$
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP FUNCTION IF EXISTS core.rls_bypass()');
        $this->execute('DROP FUNCTION IF EXISTS core.current_group_ids()');
        $this->execute('DROP FUNCTION IF EXISTS core.current_user_id()');
        $this->execute('DROP TABLE IF EXISTS core.group_resource_permissions');
        $this->execute('DROP TABLE IF EXISTS core.resources');
    }
}
