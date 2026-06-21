<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Maintenance mode — Phase 4: the critical-action state machine
 * (docs/maintenance-mode-design.md §4.1/§4.2).
 *
 * One row per critical action executed inside a maintenance window, tracking it
 * through its lifecycle so two invariants hold:
 *  - "exit only when stable" (§3/decision #4): the operator may release maintenance
 *    only when NO action is in a non-terminal state (quiescing/backing_up/running/
 *    verifying/rolling_back). The terminal states (succeeded/failed/aborted/
 *    needs_manual_restore) permit exit.
 *  - crash recovery (§2 CRITICAL-5): a process that dies mid-action leaves a stale
 *    `heartbeat_at`; a recovery sweep (worker + web read-time) moves it to a terminal
 *    state — pre-mutation phases to `aborted`, mutating/post phases to
 *    `needs_manual_restore` — so a crash can never deadlock the exit.
 *
 * `fence_token` is reserved for Phase 5/6 fencing (a recovered/superseded action must
 * not later commit). Platform-global (no tenant_id, no RLS) like the other
 * maintenance control tables.
 */
class CoreCriticalAction extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.critical_action (
                id                     uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                type                   text        NOT NULL,
                status                 text        NOT NULL DEFAULT 'running',
                maintenance_session_id uuid        NULL,
                actor_user_id          uuid        NULL,
                backup_id              uuid        NULL,
                pre_rollback_backup_id uuid        NULL,
                fence_token            uuid        NOT NULL DEFAULT core.uuid_generate_v7(),
                message                text        NULL,
                heartbeat_at           timestamptz NOT NULL DEFAULT now(),
                created_at             timestamptz NOT NULL DEFAULT now(),
                finished_at            timestamptz NULL,
                CONSTRAINT ck_critical_action_status CHECK (status IN (
                    'quiescing','backing_up','running','verifying','rolling_back',
                    'succeeded','failed','aborted','needs_manual_restore'
                ))
            )
            SQL);
        // Partial index over the non-terminal states: powers the cheap
        // "is anything still running?" exit gate and the recovery sweep.
        $this->execute(
            'CREATE INDEX ix_critical_action_open ON core.critical_action (heartbeat_at) '
            . "WHERE status IN ('quiescing','backing_up','running','verifying','rolling_back')",
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.critical_action');
    }
}
