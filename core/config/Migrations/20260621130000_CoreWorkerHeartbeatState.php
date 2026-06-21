<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Maintenance mode — Phase 2: the quiesce handshake column on the worker
 * heartbeat (docs/maintenance-mode-design.md §4.3 QUIESCE).
 *
 * `state` lets a worker report that it has reached the pause-gate and stopped
 * claiming new work, distinct from `last_status` (ok/warn/error) which describes
 * the health of the *last run* and whose CHECK rejects 'paused'. The GUI drain
 * view reads this to show how many workers have acknowledged the pause. Defaults
 * to 'running' so existing rows and normal cycles need no write — only the
 * pause-gate flips it via {@see \App\Service\Health\WorkerHeartbeat::markState()},
 * and the ON CONFLICT in the normal {@see \App\Service\Health\WorkerHeartbeat::beat()}
 * deliberately leaves `state` untouched (it would otherwise clobber 'paused').
 */
class CoreWorkerHeartbeatState extends BaseMigration
{
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE core.worker_heartbeats ADD COLUMN state text NOT NULL DEFAULT 'running'",
        );
        $this->execute(
            'ALTER TABLE core.worker_heartbeats ADD CONSTRAINT ck_worker_heartbeats_state '
            . "CHECK (state IN ('running','paused'))",
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.worker_heartbeats DROP CONSTRAINT IF EXISTS ck_worker_heartbeats_state');
        $this->execute('ALTER TABLE core.worker_heartbeats DROP COLUMN IF EXISTS state');
    }
}
