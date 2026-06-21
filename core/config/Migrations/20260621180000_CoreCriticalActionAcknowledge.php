<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Maintenance mode — Phase 6 (Stufe-1 needs_manual_restore acknowledgement,
 * docs/maintenance-mode-design.md §4.2/§4.3).
 *
 * A critical action whose rollback could not be (fully) completed ends in the
 * terminal state `needs_manual_restore` — the data state is uncertain and an
 * operator must restore the pre-action backup out of band (global restore stays
 * CLI-only, decision #7). The original Phase-4 design let such an action permit
 * the maintenance exit ("a crash must never deadlock the exit"); Phase 6 sharpens
 * this to the fail-closed "Flag HALTEN" the §4.2 flow always intended: an
 * UNACKNOWLEDGED needs_manual_restore now blocks the release, so the platform does
 * not come back online for everyone while the DB is in a known-uncertain state.
 *
 * This is not a deadlock — the operator acknowledges via the GUI once they have
 * restored/resolved, which records who/when and unblocks the release. The two
 * columns added here back that acknowledgement; the release gate keys on
 * `acknowledged_at IS NULL`.
 */
class CoreCriticalActionAcknowledge extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            ALTER TABLE core.critical_action
                ADD COLUMN acknowledged_at timestamptz NULL,
                ADD COLUMN acknowledged_by uuid        NULL
            SQL);
        // Powers the "is any manual restore still unacknowledged?" release gate
        // cheaply (only the few rows in this terminal state ever match).
        $this->execute(
            'CREATE INDEX ix_critical_action_needs_ack ON core.critical_action (maintenance_session_id) '
            . "WHERE status = 'needs_manual_restore' AND acknowledged_at IS NULL",
        );
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS core.ix_critical_action_needs_ack');
        $this->execute(<<<'SQL'
            ALTER TABLE core.critical_action
                DROP COLUMN IF EXISTS acknowledged_at,
                DROP COLUMN IF EXISTS acknowledged_by
            SQL);
    }
}
