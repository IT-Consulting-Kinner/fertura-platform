<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Workflow-State-Machines (Programm Tier-2; zurückgestellter P12-Ausbau).
 *
 * Ergänzt die ereignisgetriebenen ECA-Regeln um **zustandsbehaftete** Abläufe:
 * eine Definition beschreibt Zustände + Transitionen (auf Events, mit Bedingung
 * und Aktionen); je Geschäftsobjekt führt eine Instanz den aktuellen Zustand.
 */
class CoreWorkflows extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.workflow_definitions (
                id              uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                name            text        NOT NULL,
                entity_type     text        NOT NULL,
                entity_id_field text        NOT NULL DEFAULT 'entity_id',
                initial_state   text        NOT NULL,
                transitions     jsonb       NOT NULL DEFAULT '[]'::jsonb,
                active          boolean     NOT NULL DEFAULT true,
                created_at      timestamptz NOT NULL DEFAULT now(),
                updated_at      timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        $this->execute('CREATE INDEX ix_workflow_def_active ON core.workflow_definitions (active) WHERE active');
        $this->execute(
            'CREATE TRIGGER trg_workflow_def_updated_at BEFORE UPDATE ON core.workflow_definitions '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()',
        );

        $this->execute(<<<'SQL'
            CREATE TABLE core.workflow_instances (
                id            uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                definition_id uuid        NOT NULL REFERENCES core.workflow_definitions (id) ON DELETE CASCADE,
                entity_id     text        NOT NULL,
                state         text        NOT NULL,
                context       jsonb       NOT NULL DEFAULT '{}'::jsonb,
                created_at    timestamptz NOT NULL DEFAULT now(),
                updated_at    timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_workflow_instance UNIQUE (definition_id, entity_id)
            )
            SQL);
        $this->execute(
            'CREATE TRIGGER trg_workflow_inst_updated_at BEFORE UPDATE ON core.workflow_instances '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()',
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.workflow_instances');
        $this->execute('DROP TABLE IF EXISTS core.workflow_definitions');
    }
}
