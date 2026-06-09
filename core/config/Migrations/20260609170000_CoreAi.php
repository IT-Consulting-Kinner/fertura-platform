<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * AI/LLM-Primitive (Programm Tier-2, P11): Embedding-Store (pgvector) + Capability-
 * Contracts für ein provider-agnostisches LLM-Gateway (OpenAI/Anthropic/xAI/Google).
 *
 * `core.embeddings`: semantischer Index (Vektor je Inhalt) für Ähnlichkeitssuche
 * (Cosine, HNSW), sichtbarkeits-gefiltert über den Eigentümer. Dimension 1536
 * (OpenAI-Default); ein abweichendes Embedding-Modell erfordert eine Anpassung.
 */
class CoreAi extends BaseMigration
{
    public function up(): void
    {
        $this->execute('CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA public');

        $this->execute(<<<'SQL'
            CREATE TABLE core.embeddings (
                id          uuid           NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                source      text           NOT NULL,
                entity_type text           NOT NULL,
                entity_id   text           NOT NULL,
                owner_id    uuid           NULL,
                content     text           NOT NULL DEFAULT '',
                embedding   public.vector(1536) NOT NULL,
                created_at  timestamptz    NOT NULL DEFAULT now(),
                CONSTRAINT uq_embedding UNIQUE (source, entity_type, entity_id)
            )
            SQL);
        $this->execute('CREATE INDEX ix_embeddings_owner ON core.embeddings (owner_id)');
        $this->execute(
            'CREATE INDEX ix_embeddings_vec ON core.embeddings '
            . 'USING hnsw (embedding public.vector_cosine_ops)',
        );

        $this->execute(<<<'SQL'
            INSERT INTO core.contracts
                (owner_module_key, name, contract_type, version, multi_use, active, description)
            VALUES
                ('core', 'core.ai.complete', 'service', '1.0.0', true, true,
                 'LLM-Textvervollständigung über das provider-agnostische AI-Gateway.'),
                ('core', 'core.ai.embed', 'service', '1.0.0', true, true,
                 'Text-Embeddings über das provider-agnostische AI-Gateway.')
            ON CONFLICT (name) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        $this->execute("DELETE FROM core.contracts WHERE name IN ('core.ai.complete', 'core.ai.embed')");
        $this->execute('DROP TABLE IF EXISTS core.embeddings');
        // Extension bewusst NICHT droppen (kann von anderen Objekten genutzt werden).
    }
}
