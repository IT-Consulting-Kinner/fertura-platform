<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Konfigurationsspeicher (Step 4): zentrale Core-/Modul-Settings (Kap. 1.4 /
 * 23.3, Entscheidungen 159/164/176).
 *
 * - Key/Value je (namespace, config_key); strukturierte Werte als JSONB (30.5).
 * - Geheimnisse werden verschlüsselt abgelegt (AES-256-GCM, Entscheidung 159):
 *   Klartext nur in `value`, Chiffrat in `value_encrypted` (is_secret = true).
 * - Akteur-Spalten (E8) + updated_at-Trigger.
 */
class CoreSettings extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.settings (
                id              uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                namespace       text        NOT NULL,
                config_key      text        NOT NULL,
                value           jsonb       NULL,
                value_encrypted text        NULL,
                is_secret       boolean     NOT NULL DEFAULT false,
                created_by      uuid        NULL,
                updated_by      uuid        NULL,
                created_at      timestamptz NOT NULL DEFAULT now(),
                updated_at      timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_settings_ns_key UNIQUE (namespace, config_key),
                -- Geheimnisse nutzen value_encrypted, niemals den Klartext value.
                CONSTRAINT ck_settings_secret CHECK (NOT is_secret OR value IS NULL),
                CONSTRAINT fk_settings_created_by
                    FOREIGN KEY (created_by) REFERENCES core.users(id) ON DELETE SET NULL,
                CONSTRAINT fk_settings_updated_by
                    FOREIGN KEY (updated_by) REFERENCES core.users(id) ON DELETE SET NULL
            )
            SQL);
        $this->execute('CREATE INDEX gin_settings_value ON core.settings USING gin (value)');
        $this->execute(
            'CREATE TRIGGER trg_settings_set_updated_at BEFORE UPDATE ON core.settings '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()'
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.settings');
    }
}
