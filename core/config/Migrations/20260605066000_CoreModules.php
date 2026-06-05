<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Modul-Stammdaten & Lifecycle (Step 7, Kap. 24).
 *
 * - modules: Stammdaten + Zustandsautomat + Manifest-Kopie (jsonb).
 * - module_dependencies: Modul→Modul-Abhängigkeiten (per Schlüssel + Version).
 * - module_migrations_log: Tracking ausgeführter Modul-Migrationen (Audit).
 *
 * Registrierungen (Provider/Collector/Listener) nutzen die bestehende
 * contract_registrations-Tabelle aus Step 5 (kein Duplikat).
 */
class CoreModules extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.modules (
                id                        uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                module_key                text        NOT NULL,
                name                      text        NOT NULL,
                version                   text        NOT NULL,
                type                      text        NOT NULL,
                edition                   text        NOT NULL DEFAULT 'free',
                publisher                 text        NULL,
                php_namespace             text        NULL,
                core_compatibility        text        NOT NULL,
                extends_main_module       text        NULL,
                main_module_compatibility text        NULL,
                requires_license          boolean     NOT NULL DEFAULT false,
                status                    text        NOT NULL DEFAULT 'installed_inactive',
                manifest                  jsonb       NOT NULL,
                source_path               text        NULL,
                installed_at              timestamptz NOT NULL DEFAULT now(),
                activated_at              timestamptz NULL,
                deactivated_at            timestamptz NULL,
                created_by                uuid        NULL,
                updated_by                uuid        NULL,
                created_at                timestamptz NOT NULL DEFAULT now(),
                updated_at                timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_modules_key UNIQUE (module_key),
                CONSTRAINT ck_modules_type CHECK (type IN ('main','extension')),
                CONSTRAINT ck_modules_edition CHECK (edition IN ('free','commercial')),
                CONSTRAINT ck_modules_status
                    CHECK (status IN ('installed_inactive','active','inactive','error_install','error_activate')),
                CONSTRAINT ck_modules_version CHECK (version ~ '^\d+\.\d+\.\d+$'),
                CONSTRAINT fk_modules_created_by
                    FOREIGN KEY (created_by) REFERENCES core.users(id) ON DELETE SET NULL,
                CONSTRAINT fk_modules_updated_by
                    FOREIGN KEY (updated_by) REFERENCES core.users(id) ON DELETE SET NULL
            )
            SQL);
        $this->execute('CREATE INDEX ix_modules_status ON core.modules (status)');
        $this->execute('CREATE INDEX ix_modules_type ON core.modules (type)');
        $this->execute(
            'CREATE TRIGGER trg_modules_set_updated_at BEFORE UPDATE ON core.modules '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()'
        );

        $this->execute(<<<'SQL'
            CREATE TABLE core.module_dependencies (
                id                uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                module_id         uuid        NOT NULL,
                required_module_key text      NOT NULL,
                required_version  text        NULL,
                created_at        timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fk_moddep_module
                    FOREIGN KEY (module_id) REFERENCES core.modules(id) ON DELETE CASCADE,
                CONSTRAINT uq_moddep UNIQUE (module_id, required_module_key)
            )
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE core.module_migrations_log (
                id             uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                module_id      uuid        NOT NULL,
                migration_name text        NOT NULL,
                status         text        NOT NULL,
                error_message  text        NULL,
                executed_at    timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fk_modmig_module
                    FOREIGN KEY (module_id) REFERENCES core.modules(id) ON DELETE CASCADE,
                CONSTRAINT ck_modmig_status CHECK (status IN ('success','failed')),
                CONSTRAINT uq_modmig UNIQUE (module_id, migration_name)
            )
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.module_migrations_log');
        $this->execute('DROP TABLE IF EXISTS core.module_dependencies');
        $this->execute('DROP TABLE IF EXISTS core.modules');
    }
}
