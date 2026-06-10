<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Mandanten-Scoping für Such- und Embedding-Index (B6-Entscheidung).
 *
 * Schließt das einzige konkrete mandantenübergreifende Leck: öffentliche
 * (owner=NULL) Dokumente waren bisher mandantenübergreifend sichtbar. `tenant_id`
 * wird ergänzt (Default-Mandant für Bestand/Single-Org), der Eindeutigkeitsschlüssel
 * wird **pro Mandant** geführt (gleiche Entity-ID kann in mehreren Mandanten
 * existieren), und die Sichtbarkeit wird in den Service-Abfragen mandantenscharf
 * gefiltert — konsistent zur bereits **anwendungsseitigen** Owner-Sichtbarkeit
 * dieser Tabellen (keine RLS auf diesen schreib-intensiven Indizes).
 */
class CoreSearchTenancy extends BaseMigration
{
    private const DEFAULT_TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function up(): void
    {
        foreach (['search_index' => 'uq_search_doc', 'embeddings' => 'uq_embedding'] as $table => $uq) {
            $this->execute(
                "ALTER TABLE core.$table ADD COLUMN tenant_id uuid NOT NULL DEFAULT '" . self::DEFAULT_TENANT_ID . "'",
            );
            $this->execute(
                "ALTER TABLE core.$table ADD CONSTRAINT fk_{$table}_tenant "
                . 'FOREIGN KEY (tenant_id) REFERENCES core.tenants (id)',
            );
            // Eindeutigkeit pro Mandant statt global.
            $this->execute("ALTER TABLE core.$table DROP CONSTRAINT $uq");
            $this->execute(
                "ALTER TABLE core.$table ADD CONSTRAINT $uq UNIQUE (tenant_id, source, entity_type, entity_id)",
            );
            $this->execute("CREATE INDEX ix_{$table}_tenant ON core.$table (tenant_id)");
        }
    }

    public function down(): void
    {
        foreach (['search_index' => 'uq_search_doc', 'embeddings' => 'uq_embedding'] as $table => $uq) {
            $this->execute("DROP INDEX IF EXISTS core.ix_{$table}_tenant");
            $this->execute("ALTER TABLE core.$table DROP CONSTRAINT IF EXISTS $uq");
            $this->execute(
                "ALTER TABLE core.$table ADD CONSTRAINT $uq UNIQUE (source, entity_type, entity_id)",
            );
            $this->execute("ALTER TABLE core.$table DROP CONSTRAINT IF EXISTS fk_{$table}_tenant");
            $this->execute("ALTER TABLE core.$table DROP COLUMN IF EXISTS tenant_id");
        }
    }
}
