<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Markierung „abgeleitete Verarbeitung erledigt" auf der Event-Outbox
 * (Peer-Review-Härtung): Automations-/Workflow-Auswertung wird genau einmal
 * ausgeführt — gesteuert über ein **persistiertes Flag** statt über
 * `attempt_count==1`. So gehen die abgeleiteten Aktionen (Benachrichtigungen/
 * Folge-Events/Zustandsübergänge) bei einem Worker-Absturz + Reclaim **nicht
 * verloren** (at-least-once statt at-most-once).
 */
class CoreOutboxDerivedFlag extends BaseMigration
{
    public function up(): void
    {
        $this->execute(
            'ALTER TABLE core.event_outbox ADD COLUMN IF NOT EXISTS derived_done boolean NOT NULL DEFAULT false',
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.event_outbox DROP COLUMN IF EXISTS derived_done');
    }
}
