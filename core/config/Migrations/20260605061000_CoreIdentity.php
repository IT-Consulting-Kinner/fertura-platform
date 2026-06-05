<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Core-Identitätsmodell (Step 2a): Benutzer, Gruppen, Mitgliedschaften,
 * Administrationsbereiche (scoped admin), API-Tokens, Anmeldeschutz.
 *
 * Anforderungen: Kap. 27, Entscheidungen 135, 160, 162, 170, 171.
 * Konventionen: DB_CONVENTIONS.md. Primärschlüssel: UUIDv7 (E6),
 * Akteur-Spalten created_by/updated_by + deactivated_at auf Fachtabellen (E8).
 */
class CoreIdentity extends BaseMigration
{
    public function up(): void
    {
        // ---- Benutzer -------------------------------------------------------
        $this->execute(<<<'SQL'
            CREATE TABLE core.users (
                id             uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                username       text        NOT NULL,
                email          text        NOT NULL,
                password_hash  text        NULL,
                first_name     text        NULL,
                last_name      text        NULL,
                status         text        NOT NULL DEFAULT 'invited',
                locale         text        NULL,
                timezone       text        NULL,
                deactivated_at timestamptz NULL,
                anonymized_at  timestamptz NULL,
                created_by     uuid        NULL,
                updated_by     uuid        NULL,
                created_at     timestamptz NOT NULL DEFAULT now(),
                updated_at     timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT ck_users_status
                    CHECK (status IN ('invited','active','disabled','anonymized')),
                CONSTRAINT fk_users_created_by
                    FOREIGN KEY (created_by) REFERENCES core.users(id) ON DELETE SET NULL,
                CONSTRAINT fk_users_updated_by
                    FOREIGN KEY (updated_by) REFERENCES core.users(id) ON DELETE SET NULL
            )
            SQL);
        $this->execute('CREATE UNIQUE INDEX uq_users_username_lower ON core.users (lower(username))');
        $this->execute('CREATE UNIQUE INDEX uq_users_email_lower ON core.users (lower(email))');
        $this->execute(
            'CREATE TRIGGER trg_users_set_updated_at BEFORE UPDATE ON core.users '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()'
        );

        // ---- Gruppen --------------------------------------------------------
        $this->execute(<<<'SQL'
            CREATE TABLE core."groups" (
                id             uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                name           text        NOT NULL,
                description    text        NULL,
                active         boolean     NOT NULL DEFAULT true,
                deactivated_at timestamptz NULL,
                created_by     uuid        NULL,
                updated_by     uuid        NULL,
                created_at     timestamptz NOT NULL DEFAULT now(),
                updated_at     timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fk_groups_created_by
                    FOREIGN KEY (created_by) REFERENCES core.users(id) ON DELETE SET NULL,
                CONSTRAINT fk_groups_updated_by
                    FOREIGN KEY (updated_by) REFERENCES core.users(id) ON DELETE SET NULL
            )
            SQL);
        $this->execute('CREATE UNIQUE INDEX uq_groups_name_lower ON core."groups" (lower(name))');
        $this->execute(
            'CREATE TRIGGER trg_groups_set_updated_at BEFORE UPDATE ON core."groups" '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()'
        );

        // ---- Gruppenmitgliedschaften (Mehrfachmitgliedschaft, flach) --------
        $this->execute(<<<'SQL'
            CREATE TABLE core.groups_users (
                id         uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                group_id   uuid        NOT NULL,
                user_id    uuid        NOT NULL,
                created_by uuid        NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fk_groups_users_group
                    FOREIGN KEY (group_id) REFERENCES core."groups"(id) ON DELETE CASCADE,
                CONSTRAINT fk_groups_users_user
                    FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE CASCADE,
                CONSTRAINT fk_groups_users_created_by
                    FOREIGN KEY (created_by) REFERENCES core.users(id) ON DELETE SET NULL,
                CONSTRAINT uq_groups_users_group_user UNIQUE (group_id, user_id)
            )
            SQL);
        $this->execute('CREATE INDEX ix_groups_users_user ON core.groups_users (user_id)');

        // ---- Administrationsbereiche (feste Menge, Entscheidung 170) --------
        $this->execute(<<<'SQL'
            CREATE TABLE core.admin_areas (
                area_key   text NOT NULL PRIMARY KEY,
                label      text NOT NULL,
                sort_order int  NOT NULL
            )
            SQL);
        $this->execute(<<<'SQL'
            INSERT INTO core.admin_areas (area_key, label, sort_order) VALUES
                ('user_group_admin',     'Benutzer- und Gruppenverwaltung',  10),
                ('module_lifecycle',     'Modul- und Lifecycle-Verwaltung',  20),
                ('marketplace_license',  'Marketplace und Lizenzverwaltung', 30),
                ('registry_contracts',   'Registry und Contracts',           40),
                ('update_manager',       'Update-Manager',                   50),
                ('core_config',          'Core-Konfiguration',               60)
            SQL);

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

        // ---- API-Tokens (Entscheidung 162) ----------------------------------
        $this->execute(<<<'SQL'
            CREATE TABLE core.api_tokens (
                id           uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                user_id      uuid        NOT NULL,
                token_hash   text        NOT NULL,
                label        text        NULL,
                last_used_at timestamptz NULL,
                expires_at   timestamptz NULL,
                revoked_at   timestamptz NULL,
                created_at   timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fk_api_tokens_user
                    FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE CASCADE,
                CONSTRAINT uq_api_tokens_token_hash UNIQUE (token_hash)
            )
            SQL);
        $this->execute('CREATE INDEX ix_api_tokens_user ON core.api_tokens (user_id)');

        // ---- Anmeldeschutz (Entscheidung 162) -------------------------------
        $this->execute(<<<'SQL'
            CREATE TABLE core.auth_failures (
                id          uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                identifier  text        NOT NULL,
                ip_address  inet        NULL,
                occurred_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        $this->execute('CREATE INDEX ix_auth_failures_identifier_time ON core.auth_failures (lower(identifier), occurred_at)');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.auth_failures');
        $this->execute('DROP TABLE IF EXISTS core.api_tokens');
        $this->execute('DROP TABLE IF EXISTS core.user_admin_areas');
        $this->execute('DROP TABLE IF EXISTS core.admin_areas');
        $this->execute('DROP TABLE IF EXISTS core.groups_users');
        $this->execute('DROP TABLE IF EXISTS core."groups"');
        $this->execute('DROP TABLE IF EXISTS core.users');
    }
}
