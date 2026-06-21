<?php
declare(strict_types=1);

namespace App\Service\System;

use App\Service\Backup\BackupService;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use RuntimeException;
use stdClass;

/**
 * Critical-action state machine — Phases 4–6 (docs/maintenance-mode-design.md §4.1/§4.2).
 *
 * Tracks each critical action through its lifecycle so maintenance can enforce
 * "exit only when stable" (no action in a non-terminal state) and recover from a
 * crash without deadlocking the exit. Phase 6 runs REAL actions: the operator
 * {@see enqueue()}s an action (status='quiescing') and the worker
 * {@see claimEngaged()}s it once the platform is quiesced, then {@see CriticalActionRunner}
 * drives backup → execute → verify → succeed/rollback.
 *
 * Fencing (Phase 6): the state transitions accept the action's `fence_token`. Once
 * {@see recoverStale()} sweeps a stale action it ROTATES the token, so a resumed
 * zombie process — still holding the old token — can no longer transition the row
 * (it would, e.g., mark a half-finished install 'succeeded').
 *
 * The table is platform-global (no RLS); reads/writes go through the default
 * connection (privileged in the worker, app-role in the web tier — no RLS to bypass).
 */
class CriticalActionService
{
    /** States in which an action is still in flight — these BLOCK the exit. */
    public const NON_TERMINAL = ['quiescing', 'backing_up', 'running', 'verifying', 'rolling_back'];

    /** Pre-mutation phases: a crash here is safe to abort (nothing changed yet). */
    private const PRE_MUTATION = ['quiescing', 'backing_up'];

    /** SQL fragment listing the non-terminal states (guards every transition). */
    private const NON_TERMINAL_SQL = "('quiescing','backing_up','running','verifying','rolling_back')";

    /**
     * Stale window for the maintenance recovery path. Generous because a real action
     * (pre-action backup = pg_dump + probe-restore, then module migrations) has long
     * phases without an intra-phase heartbeat; a too-short window would falsely sweep
     * a healthy long-running action. The runner refreshes the heartbeat at every phase
     * boundary, so only a SINGLE phase exceeding this is at risk. A real crash takes up
     * to this long to recover — acceptable for an operator-supervised window (CLI
     * break-glass exists). Follow-up: thread an intra-phase heartbeat into createLocked
     * + the install for very large datasets (review #4/#6).
     */
    public const STALE_SECONDS = 1800;

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $c */
        $c = ConnectionManager::get('default');

        return $c;
    }

    /**
     * Begins a critical action in the given initial state (default 'running').
     * Returns the row including its `fence_token`.
     *
     * @return array<string,mixed>
     */
    public function start(
        string $type,
        ?string $sessionId = null,
        ?string $actorId = null,
        string $status = 'running',
    ): array {
        $row = $this->conn()->execute(
            'INSERT INTO core.critical_action (type, status, maintenance_session_id, actor_user_id) '
            . 'VALUES (:t, :s, :sess, :a) '
            . 'RETURNING id, type, status, fence_token, heartbeat_at',
            ['t' => $type, 's' => $status, 'sess' => $sessionId, 'a' => $actorId],
        )->fetch('assoc');

        /** @var array<string,mixed> $row */
        return $row;
    }

    /**
     * Queues a critical action for the worker to run once the platform is quiesced.
     * Starts in `quiescing` (queued) and carries the action parameters in `payload`.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function enqueue(string $type, ?string $sessionId, ?string $actorId, array $payload = []): array
    {
        $row = $this->conn()->execute(
            'INSERT INTO core.critical_action (type, status, maintenance_session_id, actor_user_id, payload) '
            . "VALUES (:t, 'quiescing', :sess, :a, CAST(:p AS jsonb)) "
            . 'RETURNING id, type, status, fence_token, payload',
            ['t' => $type, 'sess' => $sessionId, 'a' => $actorId, 'p' => json_encode($payload === [] ? new stdClass() : $payload)],
        )->fetch('assoc');

        /** @var array<string,mixed> $row */
        return $row;
    }

    /**
     * Atomically claims the oldest queued action of $sessionId (quiescing →
     * backing_up) with FOR UPDATE SKIP LOCKED so parallel workers never double-claim.
     * Returns the claimed row (with fence_token + payload) or null when none queued.
     *
     * @return array<string,mixed>|null
     */
    public function claimEngaged(string $sessionId): ?array
    {
        $row = $this->conn()->execute(
            "UPDATE core.critical_action SET status = 'backing_up', heartbeat_at = now() "
            . 'WHERE id = (SELECT id FROM core.critical_action '
            . "WHERE status = 'quiescing' AND maintenance_session_id = :sess "
            . 'ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED) '
            . 'RETURNING id, type, status, fence_token, payload, backup_id',
            ['sess' => $sessionId],
        )->fetch('assoc');

        return $row === false ? null : $row;
    }

    /**
     * Recent critical actions of a maintenance session, newest first (for the GUI).
     *
     * @return list<array<string,mixed>>
     */
    public function forSession(string $sessionId, int $limit = 20): array
    {
        return array_values($this->conn()->execute(
            'SELECT id, type, status, message, backup_id, created_at, finished_at '
            . 'FROM core.critical_action WHERE maintenance_session_id = :s ORDER BY created_at DESC LIMIT :lim',
            ['s' => $sessionId, 'lim' => $limit],
        )->fetchAll('assoc'));
    }

    /** @return array<string,mixed>|null */
    public function get(string $id): ?array
    {
        $row = $this->conn()->execute(
            'SELECT * FROM core.critical_action WHERE id = :id',
            ['id' => $id],
        )->fetch('assoc');

        return $row === false ? null : $row;
    }

    /**
     * Moves an action to a new state and refreshes its heartbeat. Guarded twice: it
     * only applies while the action is still in flight (no overwrite of a finalised
     * state), and — when $fence is given — only while the action still holds that
     * fence_token, so a zombie whose token was rotated by recoverStale() cannot
     * advance it.
     */
    public function transition(string $id, string $status, ?string $fence = null): bool
    {
        $terminal = !in_array($status, self::NON_TERMINAL, true);
        $sql = 'UPDATE core.critical_action SET status = :s, heartbeat_at = now(), '
            . 'finished_at = CASE WHEN :term THEN now() ELSE finished_at END '
            . 'WHERE id = :id AND status IN ' . self::NON_TERMINAL_SQL;
        $params = ['s' => $status, 'term' => $terminal ? 'true' : 'false', 'id' => $id];
        if ($fence !== null) {
            $sql .= ' AND fence_token = :fence';
            $params['fence'] = $fence;
        }

        return $this->conn()->execute($sql, $params)->rowCount() > 0;
    }

    public function markSucceeded(string $id, ?string $fence = null): bool
    {
        return $this->transition($id, 'succeeded', $fence);
    }

    public function markFailed(string $id, ?string $message = null, ?string $fence = null): bool
    {
        $this->setMessage($id, $message);

        return $this->transition($id, 'failed', $fence);
    }

    /**
     * Terminal state for an action whose rollback could not be (fully) completed:
     * maintenance is held and the operator must restore the pre-action backup via the
     * CLI (global restore is deliberately NOT GUI-reachable, decision #7).
     */
    public function markNeedsManualRestore(string $id, ?string $message = null, ?string $fence = null): bool
    {
        $this->setMessage($id, $message);

        return $this->transition($id, 'needs_manual_restore', $fence);
    }

    /** Links a (pre-action) backup to the action (status- + fence-guarded). */
    public function attachBackup(string $id, string $backupId, ?string $fence = null): void
    {
        $this->attachField('backup_id', $id, $backupId, $fence);
    }

    /** Links the snapshot taken right before attempting a rollback (Phase 6). */
    public function attachPreRollbackBackup(string $id, string $backupId, ?string $fence = null): void
    {
        $this->attachField('pre_rollback_backup_id', $id, $backupId, $fence);
    }

    private function attachField(string $column, string $id, string $backupId, ?string $fence): void
    {
        $sql = "UPDATE core.critical_action SET $column = :b, heartbeat_at = now() "
            . 'WHERE id = :id AND status IN ' . self::NON_TERMINAL_SQL;
        $params = ['b' => $backupId, 'id' => $id];
        if ($fence !== null) {
            $sql .= ' AND fence_token = :fence';
            $params['fence'] = $fence;
        }
        $this->conn()->execute($sql, $params);
    }

    /**
     * The mandatory pre-action backup phase (Phase 5; design §4.2 PRE_ACTION_BACKUP):
     * moves the action to `backing_up`, creates an enforced-encrypted, probe-verified
     * backup via {@see BackupService::createLocked()}, links it, and returns the
     * backup id. On failure it throws and leaves the action in `backing_up` — a
     * pre-mutation state the recovery sweep aborts cleanly, so no rollback is needed.
     */
    public function backupGate(string $actionId, ?string $actorId = null, ?BackupService $backup = null): string
    {
        if (!$this->transition($actionId, 'backing_up')) {
            throw new RuntimeException('Kritische Aktion nicht im Zustand fuer ein Pre-Action-Backup (bereits terminal?).');
        }
        $backup ??= new BackupService();
        $backupId = $backup->context('gui', $actorId)->createLocked('pre-action ' . $actionId, $actorId);
        $this->attachBackup($actionId, $backupId);

        return $backupId;
    }

    /**
     * Liveness ping; a missed ping past the stale window triggers crash recovery.
     * No-op once the action is terminal or its fence was rotated.
     */
    public function heartbeat(string $id, ?string $fence = null): void
    {
        $sql = 'UPDATE core.critical_action SET heartbeat_at = now() '
            . 'WHERE id = :id AND status IN ' . self::NON_TERMINAL_SQL;
        $params = ['id' => $id];
        if ($fence !== null) {
            $sql .= ' AND fence_token = :fence';
            $params['fence'] = $fence;
        }
        $this->conn()->execute($sql, $params);
    }

    /**
     * Whether any action is still in flight (the "exit only when stable" gate).
     * Optionally scoped to one maintenance session.
     */
    public function hasNonTerminal(?string $sessionId = null): bool
    {
        $sql = 'SELECT EXISTS(SELECT 1 FROM core.critical_action WHERE status IN ' . self::NON_TERMINAL_SQL;
        $params = [];
        if ($sessionId !== null) {
            $sql .= ' AND maintenance_session_id = :sess';
            $params['sess'] = $sessionId;
        }
        $sql .= ') AS e';
        $row = $this->conn()->execute($sql, $params)->fetch('assoc');

        return $row !== false && ($row['e'] === true || $row['e'] === 't');
    }

    /**
     * Count of in-flight actions (for the GUI "cannot exit: N running" hint).
     * Optionally scoped to one maintenance session.
     */
    public function nonTerminalCount(?string $sessionId = null): int
    {
        $sql = 'SELECT count(*) AS c FROM core.critical_action WHERE status IN ' . self::NON_TERMINAL_SQL;
        $params = [];
        if ($sessionId !== null) {
            $sql .= ' AND maintenance_session_id = :sess';
            $params['sess'] = $sessionId;
        }
        $row = $this->conn()->execute($sql, $params)->fetch('assoc');

        return (int)$row['c'];
    }

    /**
     * Crash recovery: moves stale non-terminal actions to a terminal state so a dead
     * process can never deadlock the exit. Pre-mutation phases (quiescing/backing_up)
     * abort cleanly; mutating/post phases (running/verifying/rolling_back) go to
     * `needs_manual_restore` because the data state is uncertain and must not be
     * silently declared good. ROTATES fence_token so a process that later resumes on
     * the swept row cannot transition it (it holds the old token). Returns the count.
     */
    /** Recovery sweep for the maintenance path, using the generous {@see STALE_SECONDS}. */
    public function maintenanceRecoverStale(): int
    {
        return $this->recoverStale(self::STALE_SECONDS);
    }

    public function recoverStale(int $staleSeconds = 120): int
    {
        $statement = $this->conn()->execute(
            'UPDATE core.critical_action SET '
            . "status = CASE WHEN status IN (:pre1, :pre2) THEN 'aborted' ELSE 'needs_manual_restore' END, "
            . "message = trim(both ' ' from coalesce(message, '') || ' [recovered: stale heartbeat]'), "
            . 'fence_token = core.uuid_generate_v7(), '
            . 'finished_at = now() '
            . 'WHERE status IN ' . self::NON_TERMINAL_SQL . ' '
            . "AND heartbeat_at < now() - (:secs || ' seconds')::interval",
            ['pre1' => self::PRE_MUTATION[0], 'pre2' => self::PRE_MUTATION[1], 'secs' => (string)max(1, $staleSeconds)],
        );

        return $statement->rowCount();
    }

    private function setMessage(string $id, ?string $message): void
    {
        if ($message === null) {
            return;
        }
        $this->conn()->execute(
            'UPDATE core.critical_action SET message = :m WHERE id = :id',
            ['m' => mb_substr($message, 0, 2000), 'id' => $id],
        );
    }
}
