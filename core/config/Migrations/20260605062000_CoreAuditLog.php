<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Core-Audit-Log (Step 3): unveränderliches, zeitbereichs-partitioniertes
 * Audit-Log mit JSONB-Payload und referenzrobusten, textuellen Bezeichnern.
 *
 * Anforderungen: Kap. 1.6 (Audit-Grundregel), 24.16/24.16.1 + Entscheidung 163
 * (Referenzrobustheit), 20.6 (Unveränderlichkeit/Partitionierung), 27.18
 * (Identity-Ereignisse), 30.5 (JSONB/GIN), 30.8 + Entscheidung 179 (Partitionierung).
 *
 * Designentscheidung E16: Personen werden per auflösbarer UUID referenziert
 * (kein denormalisierter Klartext) -> Anonymisierung wirkt ohne Log-Mutation.
 * Textuelle Schnappschüsse nur für nicht-personenbezogene Entitäten (Module/Config).
 */
class CoreAuditLog extends BaseMigration
{
    public function up(): void
    {
        // Partitionierte Eltern-Tabelle (RANGE über created_at).
        // PK muss den Partitionsschlüssel enthalten -> (id, created_at).
        $this->execute(<<<'SQL'
            CREATE TABLE core.audit_log (
                id             uuid        NOT NULL DEFAULT core.uuid_generate_v7(),
                created_at     timestamptz NOT NULL DEFAULT now(),
                actor_user_id  uuid        NULL,
                action         text        NOT NULL,
                entity_type    text        NOT NULL,
                entity_id      text        NULL,
                entity_label   text        NULL,
                module_key     text        NULL,
                module_name    text        NULL,
                module_version text        NULL,
                component      text        NOT NULL DEFAULT 'core',
                correlation_id uuid        NULL,
                old_value      jsonb       NULL,
                new_value      jsonb       NULL,
                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at)
            SQL);
        $this->execute(
            "COMMENT ON TABLE core.audit_log IS "
            . "'Unveränderliches, partitioniertes Audit-Log (append-only). "
            . "Personen per auflösbarer UUID (actor_user_id), referenzrobuste "
            . "Textkopien nur für nicht-personenbezogene Entitäten.'"
        );

        // DEFAULT-Partition als Sicherheitsnetz; Monatspartitionen erzeugt
        // `bin/cake audit_partition` (Entrypoint) VOR dem ersten Schreiben.
        $this->execute('CREATE TABLE core.audit_log_default PARTITION OF core.audit_log DEFAULT');

        // Indizes auf der partitionierten Eltern-Tabelle (propagieren auf Partitionen).
        $this->execute('CREATE INDEX ix_audit_log_actor ON core.audit_log (actor_user_id, created_at DESC)');
        $this->execute('CREATE INDEX ix_audit_log_entity ON core.audit_log (entity_type, entity_id, created_at DESC)');
        $this->execute('CREATE INDEX ix_audit_log_action ON core.audit_log (action, created_at DESC)');
        $this->execute('CREATE INDEX ix_audit_log_module ON core.audit_log (module_key, created_at DESC)');
        $this->execute('CREATE INDEX ix_audit_log_correlation ON core.audit_log (correlation_id)');
        $this->execute('CREATE INDEX gin_audit_log_new_value ON core.audit_log USING gin (new_value)');
        $this->execute('CREATE INDEX gin_audit_log_old_value ON core.audit_log USING gin (old_value)');

        // Unveränderlichkeit (Kap. 20.6): UPDATE/DELETE blockieren.
        // Ausnahme nur unter explizitem Bypass (privilegierte Wartung):
        //   SET LOCAL app.allow_audit_mutation = 'on';
        $this->execute(<<<'SQL'
            CREATE OR REPLACE FUNCTION core.audit_log_immutable()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $func$
            BEGIN
                IF current_setting('app.allow_audit_mutation', true) = 'on' THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;
                RAISE EXCEPTION 'core.audit_log ist append-only (% nicht erlaubt)', TG_OP;
            END
            $func$
            SQL);
        $this->execute(
            'CREATE TRIGGER trg_audit_log_immutable '
            . 'BEFORE UPDATE OR DELETE ON core.audit_log '
            . 'FOR EACH ROW EXECUTE FUNCTION core.audit_log_immutable()'
        );
    }

    public function down(): void
    {
        // DROP TABLE entfernt Partitionen, Indizes und den Trigger mit.
        $this->execute('DROP TABLE IF EXISTS core.audit_log');
        $this->execute('DROP FUNCTION IF EXISTS core.audit_log_immutable()');
    }
}
