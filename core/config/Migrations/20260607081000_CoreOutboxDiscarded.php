<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Dead-Letter-Verwaltung (Kap. 26.9.2): zusätzlicher Terminalstatus
 * `discarded`, damit manuell verworfene Events von erfolgreichen (`done`)
 * unterscheidbar bleiben.
 */
class CoreOutboxDiscarded extends BaseMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE core.event_outbox DROP CONSTRAINT IF EXISTS ck_outbox_status');
        $this->execute(
            "ALTER TABLE core.event_outbox ADD CONSTRAINT ck_outbox_status "
            . "CHECK (status IN ('pending','processing','done','dead_letter','discarded'))",
        );
    }

    public function down(): void
    {
        $this->execute("UPDATE core.event_outbox SET status = 'done' WHERE status = 'discarded'");
        $this->execute('ALTER TABLE core.event_outbox DROP CONSTRAINT IF EXISTS ck_outbox_status');
        $this->execute(
            "ALTER TABLE core.event_outbox ADD CONSTRAINT ck_outbox_status "
            . "CHECK (status IN ('pending','processing','done','dead_letter'))",
        );
    }
}
