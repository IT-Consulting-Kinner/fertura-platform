<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Volltext-Suche (Programm Tier-1, P10) als opt-in Core-Capability.
 *
 * Zentrales `search_index`: Core und Module legen durchsuchbare Dokumente ab
 * (Titel/Body + Sichtbarkeits-Eigentümer). Die `tsvector`-Spalte ist als
 * **generierte Spalte** gepflegt (gewichtet Titel>Body, Konfiguration `simple`
 * = sprachneutral, da mehrsprachige Plattform), GIN-indiziert. Module pflegen
 * den Index inkrementell (`SearchService::index`) bzw. liefern über den
 * Collector-Contract `core.collector.search` ein `reindex()`.
 */
class CoreSearch extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.search_index (
                id          uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                source      text        NOT NULL,
                entity_type text        NOT NULL,
                entity_id   text        NOT NULL,
                title       text        NOT NULL DEFAULT '',
                body        text        NOT NULL DEFAULT '',
                owner_id    uuid        NULL,
                url         text        NULL,
                tsv tsvector GENERATED ALWAYS AS (
                    setweight(to_tsvector('simple', coalesce(title, '')), 'A') ||
                    setweight(to_tsvector('simple', coalesce(body, '')), 'B')
                ) STORED,
                updated_at  timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_search_doc UNIQUE (source, entity_type, entity_id)
            )
            SQL);
        $this->execute('CREATE INDEX ix_search_tsv ON core.search_index USING GIN (tsv)');
        $this->execute('CREATE INDEX ix_search_owner ON core.search_index (owner_id)');

        $this->execute(<<<'SQL'
            INSERT INTO core.contracts
                (owner_module_key, name, contract_type, version, multi_use, active, description)
            VALUES
                ('core', 'core.collector.search', 'collector', '1.0.0', true, true,
                 'Module liefern durchsuchbare Dokumente (SearchIndexerInterface::reindex).')
            ON CONFLICT (name) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        $this->execute("DELETE FROM core.contracts WHERE name = 'core.collector.search'");
        $this->execute('DROP TABLE IF EXISTS core.search_index');
    }
}
