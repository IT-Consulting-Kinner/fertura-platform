<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Sprachbewusste Volltextsuche (Core-C-Thema „Such-Tiefe"): die `tsv`-Spalte
 * kombiniert die bisherige `simple`-Analyse (exakte, sprachunabhängige Tokens —
 * Codes/IDs etc.) mit einer **Sprachkonfiguration** (Stemming/Stopwords). Die
 * Sprache kommt aus `SEARCH_TEXT_CONFIG` (Default `german`, da die Plattform
 * primär deutschsprachig ist) und wird zugleich als Setting `search.text_config`
 * abgelegt, damit die Abfrage (SearchService) dieselbe Konfiguration verwendet.
 *
 * Backward-kompatibel: der `simple`-Anteil bleibt erhalten, exakte Treffer gehen
 * nicht verloren; ohne gültige Sprache fällt es auf reines `simple` zurück.
 */
class CoreSearchLanguage extends BaseMigration
{
    public function up(): void
    {
        $cfg = getenv('SEARCH_TEXT_CONFIG');
        $cfg = is_string($cfg) && preg_match('/^[a-z_]+$/', strtolower($cfg)) === 1 ? strtolower($cfg) : 'german';
        // Nur eine real existierende Postgres-Textkonfiguration zulassen, sonst simple.
        $row = $this->fetchRow("SELECT count(*) AS c FROM pg_ts_config WHERE cfgname = '$cfg'");
        if ((int)($row['c'] ?? 0) === 0) {
            $cfg = 'simple';
        }

        $langPart = $cfg === 'simple' ? '' : (
            " ||\n"
            . "                    setweight(to_tsvector('$cfg', coalesce(title, '')), 'A') ||\n"
            . "                    setweight(to_tsvector('$cfg', coalesce(body, '')), 'B')"
        );

        // tsv neu definieren (GENERATED-Spalte lässt sich nicht ändern -> drop+add;
        // der GIN-Index hängt an der Spalte und fällt mit ihr, danach neu anlegen).
        $this->execute('ALTER TABLE core.search_index DROP COLUMN tsv');
        $this->execute(<<<SQL
            ALTER TABLE core.search_index ADD COLUMN tsv tsvector GENERATED ALWAYS AS (
                setweight(to_tsvector('simple', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('simple', coalesce(body, '')), 'B')$langPart
            ) STORED
            SQL);
        $this->execute('CREATE INDEX ix_search_tsv ON core.search_index USING GIN (tsv)');

        // Konfiguration als globales Setting hinterlegen (Quelle der Wahrheit für die Abfrage).
        $this->execute(
            "DELETE FROM core.settings WHERE namespace = 'core' AND config_key = 'search.text_config' AND tenant_id IS NULL",
        );
        $this->execute(
            "INSERT INTO core.settings (namespace, config_key, value, is_secret) "
            . "VALUES ('core', 'search.text_config', to_jsonb('$cfg'::text), false)",
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.search_index DROP COLUMN tsv');
        $this->execute(<<<'SQL'
            ALTER TABLE core.search_index ADD COLUMN tsv tsvector GENERATED ALWAYS AS (
                setweight(to_tsvector('simple', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('simple', coalesce(body, '')), 'B')
            ) STORED
            SQL);
        $this->execute('CREATE INDEX ix_search_tsv ON core.search_index USING GIN (tsv)');
        $this->execute("DELETE FROM core.settings WHERE namespace = 'core' AND config_key = 'search.text_config'");
    }
}
