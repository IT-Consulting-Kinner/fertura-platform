<?php
declare(strict_types=1);

namespace App\Service\System;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Worker-pause signal — Phase 2 (docs/maintenance-mode-design.md §4.3 QUIESCE).
 *
 * Reads/writes the single-row `core.worker_pause` control table UNCACHED, mirroring
 * {@see MaintenanceService}: every {@see isPaused()} hits the database directly so a
 * pause takes effect on the worker's very next cycle instead of lagging behind the
 * settings cache. {@see requestPause()} stamps the server-side 120s deadline and
 * fires `pg_notify('core_worker_pause')`; the worker holds a LISTEN on that channel
 * so a pause/release wakes it immediately rather than after a full poll interval.
 *
 * The DB row is authoritative — the NOTIFY is only a latency optimisation (it is
 * dropped if no session is listening at emit time, and the worker re-opens its
 * LISTEN connection on error), so the gate always re-reads the row before acting.
 */
class WorkerPauseGate
{
    /** Postgres NOTIFY channel announcing a pause/release. */
    public const NOTIFY_CHANNEL = 'core_worker_pause';

    /** Hard deadline after which a quiesce that cannot drain is allowed to proceed. */
    public const DEFAULT_DEADLINE_SECONDS = 120;

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $c */
        $c = ConnectionManager::get('default');

        return $c;
    }

    /** Whether workers are currently asked to pause (uncached). */
    public function isPaused(): bool
    {
        $row = $this->conn()->execute(
            'SELECT paused FROM core.worker_pause WHERE id = true',
        )->fetch('assoc');

        return $row !== false && (bool)$row['paused'];
    }

    /**
     * The full pause row (paused flag, deadline, actor, linked session) or null
     * when the singleton has somehow not been seeded.
     *
     * @return array<string,mixed>|null
     */
    public function pauseRow(): ?array
    {
        $row = $this->conn()->execute(
            'SELECT paused, requested_at, deadline_at, actor_user_id, maintenance_session_id '
            . 'FROM core.worker_pause WHERE id = true',
        )->fetch('assoc');

        return $row === false ? null : $row;
    }

    /**
     * Engages the pause: workers stop claiming new work on their next cycle and a
     * server-side deadline is stamped. Upserts defensively so a missing singleton
     * row cannot leave the pause un-settable.
     */
    public function requestPause(
        ?string $actorId = null,
        ?string $sessionId = null,
        int $deadlineSeconds = self::DEFAULT_DEADLINE_SECONDS,
    ): void {
        $this->conn()->execute(
            'INSERT INTO core.worker_pause '
            . '(id, paused, requested_at, deadline_at, actor_user_id, maintenance_session_id) '
            . "VALUES (true, true, now(), now() + (:secs || ' seconds')::interval, :a, :s) "
            . 'ON CONFLICT (id) DO UPDATE SET '
            . 'paused = true, requested_at = now(), '
            . "deadline_at = now() + (:secs || ' seconds')::interval, "
            . 'actor_user_id = :a, maintenance_session_id = :s',
            ['secs' => (string)max(1, $deadlineSeconds), 'a' => $actorId, 's' => $sessionId],
        );
        $this->notify();
    }

    /** Releases the pause: workers resume on their next cycle. */
    public function release(): void
    {
        $this->conn()->execute(
            'INSERT INTO core.worker_pause (id, paused) VALUES (true, false) '
            . 'ON CONFLICT (id) DO UPDATE SET paused = false, deadline_at = NULL',
        );
        $this->notify();
    }

    /** Best-effort cluster wake-up; the uncached read stays the source of truth. */
    private function notify(): void
    {
        try {
            $this->conn()->execute("SELECT pg_notify(:c, '')", ['c' => self::NOTIFY_CHANNEL]);
        } catch (Throwable) {
            // NOTIFY is an optimisation only — never let it break pause/release.
        }
    }
}
