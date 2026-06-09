<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Automations-/Workflow-Engine (Programm Tier-2, P12): deklarative
 * Event-Condition-Action-Regeln auf der Event-Outbox.
 *
 * `automation_rules`: pro Regel ein Event-Muster (Contract, exakt/`*`/`prefix.*`),
 * eine Bedingung (JSON-Ausdruck über die Event-Nutzlast) und Aktionen
 * (Benachrichtigung/Event-Publikation). Der Worker wertet beim Event-Dispatch
 * passende, aktive Regeln aus.
 */
class CoreAutomation extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.automation_rules (
                id         uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                name       text        NOT NULL,
                event      text        NOT NULL,
                condition  jsonb       NOT NULL DEFAULT '{}'::jsonb,
                actions    jsonb       NOT NULL DEFAULT '[]'::jsonb,
                active     boolean     NOT NULL DEFAULT true,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        $this->execute(
            'CREATE INDEX ix_automation_active ON core.automation_rules (active) WHERE active',
        );
        $this->execute(
            'CREATE TRIGGER trg_automation_rules_updated_at BEFORE UPDATE ON core.automation_rules '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()',
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.automation_rules');
    }
}
