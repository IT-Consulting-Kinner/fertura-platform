<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Managed Locale Store — Metadaten (i18n-3, E38/E40).
 *
 * Der Katalog-Inhalt liegt in Dateien (PO; CakePHP liest PO zur Laufzeit direkt,
 * daher kein separates MO nötig) im persistenten Store; die DB hält nur
 * Verwaltungsmetadaten je Sprachdatei. Eindeutig je (Komponente, Locale,
 * Version) — Überschreiben bei gleicher Version (keine eigene Pack-Versionierung).
 */
class CoreLanguagePacks extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.language_packs (
                id               uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                component_type   text        NOT NULL,
                component_key    text        NOT NULL,
                locale           text        NOT NULL,
                version          text        NOT NULL,
                domain           text        NOT NULL,
                signed           boolean     NOT NULL DEFAULT false,
                reviewed         boolean     NOT NULL DEFAULT false,
                edited           boolean     NOT NULL DEFAULT false,
                source           text        NOT NULL DEFAULT 'upload',
                file_path        text        NOT NULL,
                checksum         text        NULL,
                write_state      text        NOT NULL DEFAULT 'idle',
                write_started_at timestamptz NULL,
                last_write_error text        NULL,
                uploaded_by      uuid        NULL,
                created_at       timestamptz NOT NULL DEFAULT now(),
                updated_at       timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_language_packs UNIQUE (component_type, component_key, locale, version),
                CONSTRAINT ck_lp_component_type CHECK (component_type IN ('core', 'module', 'extension')),
                CONSTRAINT ck_lp_source CHECK (source IN ('package', 'upload')),
                CONSTRAINT ck_lp_write_state CHECK (write_state IN ('idle', 'writing')),
                CONSTRAINT fk_lp_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES core.users(id) ON DELETE SET NULL
            )
            SQL);
        $this->execute('CREATE INDEX ix_language_packs_lookup ON core.language_packs (component_key, locale)');
        $this->execute(
            'CREATE TRIGGER trg_language_packs_set_updated_at BEFORE UPDATE ON core.language_packs '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()'
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.language_packs');
    }
}
