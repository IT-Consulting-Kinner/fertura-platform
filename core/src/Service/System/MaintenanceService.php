<?php
declare(strict_types=1);

namespace App\Service\System;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Maintenance-mode leading state — Phase 1 (docs/maintenance-mode-design.md).
 *
 * The single source of truth is `core.maintenance_session`, read **uncached** here:
 * every {@see activeSession()} hits the database directly. This is deliberate — the
 * settings cache (FileEngine, +5min TTL) would let other web/worker instances ignore
 * an engaged window for minutes, turning the gate into security theatre. The cost is
 * one tiny indexed lookup per check, paid only on the maintenance path.
 *
 * {@see engage()} is atomic: a partial unique index (`WHERE status <> 'closed'`)
 * means a second activator loses the `INSERT … ON CONFLICT DO NOTHING` race and gets
 * null, never a duplicate window. Engage/release additionally fire
 * `pg_notify('core_maintenance')` so long-lived processes (the worker pause-gate,
 * Phase 2) react immediately instead of waiting for their next poll.
 *
 * This complements {@see MaintenanceMode}, the file-based 503 flag used only for the
 * restore cutover, where the DB itself is being replaced and cannot hold the state.
 */
class MaintenanceService
{
    /** Postgres NOTIFY channel announcing any maintenance-state change. */
    public const NOTIFY_CHANNEL = 'core_maintenance';

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $c */
        $c = ConnectionManager::get('default');

        return $c;
    }

    /**
     * The currently open (active or operator_gone) maintenance session, read
     * straight from the database — never cached. Null when not in maintenance.
     *
     * @return array<string,mixed>|null
     */
    public function activeSession(): ?array
    {
        $row = $this->conn()->execute(
            'SELECT id, actor_user_id, allow_token_hash, status, reason, opened_at, heartbeat_at '
            . "FROM core.maintenance_session WHERE status <> 'closed' LIMIT 1",
        )->fetch('assoc');

        return $row === false ? null : $row;
    }

    /** Whether the platform is currently in maintenance (uncached). */
    public function isActive(): bool
    {
        return $this->activeSession() !== null;
    }

    /**
     * Atomically engages maintenance for $actorId. The server-issued allow-token's
     * **hash** is stored here; the plaintext is handed to the activator's browser as
     * a cookie (Phase 3 gate) so only they stay signed in. Returns the new session,
     * or null when another session is already open (lost the single-operator race).
     *
     * @return array<string,mixed>|null
     */
    public function engage(?string $actorId, string $allowTokenHash, ?string $reason = null): ?array
    {
        $row = $this->conn()->execute(
            'INSERT INTO core.maintenance_session (actor_user_id, allow_token_hash, reason) '
            . 'VALUES (:a, :h, :r) '
            . "ON CONFLICT ((status <> 'closed')) WHERE status <> 'closed' DO NOTHING "
            . 'RETURNING id, actor_user_id, allow_token_hash, status, reason, opened_at, heartbeat_at',
            ['a' => $actorId, 'h' => $allowTokenHash, 'r' => $reason],
        )->fetch('assoc');

        if ($row === false) {
            return null; // another session is already open
        }
        $this->notify();

        return $row;
    }

    /** Closes the given open session (idempotent) and announces the change. */
    public function release(string $sessionId): void
    {
        $this->conn()->execute(
            "UPDATE core.maintenance_session SET status = 'closed', closed_at = now() "
            . "WHERE id = :id AND status <> 'closed'",
            ['id' => $sessionId],
        );
        $this->notify();
    }

    /**
     * "Exit only when stable" (design §4.3 EXIT, decision #4): closes the session
     * ATOMICALLY only if no critical action of that session is still in flight — the
     * close and the in-flight check are one conditional UPDATE, so no action can slip
     * in between a separate read and the close. Returns whether it actually closed
     * (false = refused because work is still running). The caller must NOT resume the
     * workers when this returns false.
     */
    public function releaseIfStable(string $sessionId): bool
    {
        $closed = $this->conn()->execute(
            "UPDATE core.maintenance_session SET status = 'closed', closed_at = now() "
            . "WHERE id = :id AND status <> 'closed' "
            . 'AND NOT EXISTS (SELECT 1 FROM core.critical_action '
            . 'WHERE maintenance_session_id = :id '
            . "AND status IN ('quiescing','backing_up','running','verifying','rolling_back'))",
            ['id' => $sessionId],
        )->rowCount() > 0;

        if ($closed) {
            $this->notify();
        }

        return $closed;
    }

    /**
     * Refreshes the active session's liveness timestamp. Phase 9's TTL sweep flips a
     * session whose heartbeat has gone stale to `operator_gone`, so the break-glass
     * CLI can reassign or release it without the original operator.
     */
    public function heartbeat(string $sessionId): void
    {
        $this->conn()->execute(
            'UPDATE core.maintenance_session SET heartbeat_at = now() WHERE id = :id',
            ['id' => $sessionId],
        );
    }

    /** Best-effort cluster wake-up; the uncached read remains the source of truth. */
    private function notify(): void
    {
        try {
            $this->conn()->execute("SELECT pg_notify(:c, '')", ['c' => self::NOTIFY_CHANNEL]);
        } catch (Throwable) {
            // NOTIFY is an optimisation only — never let it break engage/release.
        }
    }
}
