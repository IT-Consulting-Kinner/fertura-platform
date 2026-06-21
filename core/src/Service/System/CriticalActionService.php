<?php
declare(strict_types=1);

namespace App\Service\System;

use App\Service\Backup\BackupService;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use RuntimeException;

/**
 * Critical-action state machine — Phase 4 (docs/maintenance-mode-design.md §4.1/§4.2).
 *
 * Tracks each critical action through its lifecycle so maintenance can enforce
 * "exit only when stable" (no action in a non-terminal state) and recover from a
 * crash without deadlocking the exit. Phase 5/6 wrap real actions (module install,
 * tenant provision, secret/trust rotate) with {@see start()} … {@see markSucceeded()}/
 * {@see markFailed()}; Phase 4 ships the machine, the exit gate, and the recovery
 * sweep. The table is platform-global (no RLS); reads/writes go through the default
 * connection (privileged in the worker, app-role in the web tier — no RLS to bypass).
 */
class CriticalActionService
{
    /** States in which an action is still in flight — these BLOCK the exit. */
    public const NON_TERMINAL = ['quiescing', 'backing_up', 'running', 'verifying', 'rolling_back'];

    /** Pre-mutation phases: a crash here is safe to abort (nothing changed yet). */
    private const PRE_MUTATION = ['quiescing', 'backing_up'];

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $c */
        $c = ConnectionManager::get('default');

        return $c;
    }

    /**
     * Begins a critical action in the given initial state (default 'running').
     * Returns the row including its `fence_token` (reserved for Phase 5/6 fencing).
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
     * Moves an action to a new state and refreshes its heartbeat. Guarded: it only
     * applies while the action is still in flight, so a zombie process cannot
     * overwrite a state the recovery sweep already finalised (a defensive precursor
     * to the Phase 5/6 fence_token enforcement).
     */
    public function transition(string $id, string $status): bool
    {
        $terminal = !in_array($status, self::NON_TERMINAL, true);

        return $this->conn()->execute(
            'UPDATE core.critical_action SET status = :s, heartbeat_at = now(), '
            . 'finished_at = CASE WHEN :term THEN now() ELSE finished_at END '
            . "WHERE id = :id AND status IN ('quiescing','backing_up','running','verifying','rolling_back')",
            ['s' => $status, 'term' => $terminal ? 'true' : 'false', 'id' => $id],
        )->rowCount() > 0;
    }

    public function markSucceeded(string $id): void
    {
        $this->transition($id, 'succeeded');
    }

    public function markFailed(string $id, ?string $message = null): void
    {
        $this->setMessage($id, $message);
        $this->transition($id, 'failed');
    }

    /** Links a (pre-action) backup to the action. */
    public function attachBackup(string $id, string $backupId): void
    {
        $this->conn()->execute(
            'UPDATE core.critical_action SET backup_id = :b, heartbeat_at = now() WHERE id = :id',
            ['b' => $backupId, 'id' => $id],
        );
    }

    /**
     * The mandatory pre-action backup phase (Phase 5; design §4.2 PRE_ACTION_BACKUP):
     * moves the action to `backing_up`, creates an enforced-encrypted, probe-verified
     * backup via {@see BackupService::createLocked()}, links it, and returns the
     * backup id. The action runner (Phase 6) calls this BEFORE the action mutates
     * anything. On failure it throws and leaves the action in `backing_up` — a
     * pre-mutation state the recovery sweep aborts cleanly, so no rollback is needed.
     */
    public function backupGate(string $actionId, ?string $actorId = null, ?BackupService $backup = null): string
    {
        // Refuse (before any expensive dump) if the action is not in a state that can
        // enter the backup phase — e.g. already terminal/recovered (the guarded
        // transition would otherwise no-op and we'd link a backup to a dead action).
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
     * No-op once the action is terminal, so a zombie cannot un-stale a recovered row.
     */
    public function heartbeat(string $id): void
    {
        $this->conn()->execute(
            'UPDATE core.critical_action SET heartbeat_at = now() '
            . "WHERE id = :id AND status IN ('quiescing','backing_up','running','verifying','rolling_back')",
            ['id' => $id],
        );
    }

    /**
     * Whether any action is still in flight (the "exit only when stable" gate).
     * Optionally scoped to one maintenance session.
     */
    public function hasNonTerminal(?string $sessionId = null): bool
    {
        $sql = 'SELECT EXISTS(SELECT 1 FROM core.critical_action WHERE status IN '
            . "('quiescing','backing_up','running','verifying','rolling_back')";
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
        $sql = 'SELECT count(*) AS c FROM core.critical_action WHERE status IN '
            . "('quiescing','backing_up','running','verifying','rolling_back')";
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
     * silently declared good. Returns the number recovered.
     */
    public function recoverStale(int $staleSeconds = 120): int
    {
        $statement = $this->conn()->execute(
            'UPDATE core.critical_action SET '
            . 'status = CASE WHEN status IN (:pre1, :pre2) THEN \'aborted\' ELSE \'needs_manual_restore\' END, '
            . "message = trim(both ' ' from coalesce(message, '') || ' [recovered: stale heartbeat]'), "
            . 'finished_at = now() '
            . "WHERE status IN ('quiescing','backing_up','running','verifying','rolling_back') "
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
