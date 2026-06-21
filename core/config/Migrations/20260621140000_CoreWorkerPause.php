<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Maintenance mode — Phase 2: the worker-pause signal (`core.worker.paused` in
 * docs/maintenance-mode-design.md §4.1/§4.3 QUIESCE).
 *
 * A single-row control table read UNCACHED by {@see \App\Service\System\WorkerPauseGate}
 * at the top of every worker cycle. It is modelled as a dedicated table rather
 * than a `core.settings` catalog key on purpose: the settings path is served from
 * each process's FileEngine cache for up to +5min, which would let workers keep
 * processing for minutes after a pause is engaged (the gate would be security
 * theatre). The single row is enforced by a boolean PK fixed to `true`. It also
 * carries the server-stamped 120s hard deadline (`deadline_at`) so a quiesce that
 * cannot reach in-flight=0 (e.g. a stuck delivery) still terminates, and the
 * linkage back to the maintenance session that requested it. Platform-global
 * (no tenant_id, no RLS) like core.worker_heartbeats and core.maintenance_session.
 */
class CoreWorkerPause extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.worker_pause (
                id                     boolean     NOT NULL PRIMARY KEY DEFAULT true,
                paused                 boolean     NOT NULL DEFAULT false,
                requested_at           timestamptz NULL,
                deadline_at            timestamptz NULL,
                actor_user_id          uuid        NULL,
                maintenance_session_id uuid        NULL,
                updated_at             timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT ck_worker_pause_singleton CHECK (id = true)
            )
            SQL);
        $this->execute(
            'CREATE TRIGGER trg_worker_pause_set_updated_at BEFORE UPDATE ON core.worker_pause '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()',
        );
        // Seed the singleton so the gate's read and the engage/release UPDATEs hit
        // an existing row (the service still upserts defensively).
        $this->execute('INSERT INTO core.worker_pause (id, paused) VALUES (true, false)');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.worker_pause');
    }
}
