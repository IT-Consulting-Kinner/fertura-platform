<?php
declare(strict_types=1);

namespace App\Service\System;

use App\Infrastructure\Db;
use Cake\Database\Connection;

/**
 * Quiesce/drain orchestration — Phase 2 (docs/maintenance-mode-design.md §4.3).
 *
 * Engages the worker pause ({@see WorkerPauseGate}) and answers "is it safe to run
 * the critical action yet?" by counting all genuinely in-flight work. Reads go
 * through {@see Db::privileged()} because one source — `webhook_deliveries` — is
 * RLS-protected (E167); the web status endpoint runs as the NOBYPASSRLS app role
 * and would otherwise undercount to the request's tenant. The privileged connection
 * falls back to 'default' in dev without separate roles.
 *
 * Drain is complete when {@see inFlight()} reaches 0. Because a stuck row (e.g. a
 * delivery left 'delivering' by a crash with no reclaim sweep) could otherwise block
 * forever, {@see WorkerPauseGate} stamps a hard deadline; {@see status()} reports
 * `timed_out` once it elapses with work still outstanding so the operator can
 * proceed. The "two consecutive polls at 0" rule that finally declares the system
 * drained lives in the caller (the GUI poller), since a worker mid-cycle can briefly
 * read stale.
 */
class QuiesceService
{
    private WorkerPauseGate $gate;

    public function __construct(?WorkerPauseGate $gate = null)
    {
        $this->gate = $gate ?? new WorkerPauseGate();
    }

    private function conn(): Connection
    {
        return Db::privileged();
    }

    /**
     * Counts every in-flight work source across all tenants (system scope). The
     * `*_due` predicates mirror the workers' own claim queries (pending AND due
     * now); backoff-delayed retries (available_at in the future) are intentionally
     * excluded — they are not work the drain must wait for.
     *
     * Note: `jobs_reserved` covers the DB job-queue driver only. The Redis Streams
     * driver keeps in-flight work in XPENDING, which this does not count; there is
     * no in-worker consumer of that queue today, so the DB count is sufficient. If
     * a Redis-backed consumer is wired into the pause-gate, add an XPENDING source.
     *
     * @return array<string,int> per-source counts plus a `total`
     */
    public function inFlight(): array
    {
        $row = $this->conn()->execute(<<<'SQL'
            SELECT
                (SELECT count(*) FROM event_outbox WHERE status = 'processing') AS outbox_processing,
                (SELECT count(*) FROM event_outbox WHERE status = 'pending' AND available_at <= now()) AS outbox_due,
                (SELECT count(*) FROM webhook_deliveries WHERE status = 'delivering') AS webhook_delivering,
                (SELECT count(*) FROM webhook_deliveries
                    WHERE status = 'pending' AND available_at <= now()) AS webhook_due,
                (SELECT count(*) FROM module_install_jobs WHERE status IN ('queued','running')) AS install_jobs,
                (SELECT count(*) FROM job_queue WHERE status = 'reserved') AS jobs_reserved
            SQL,)->fetch('assoc');

        $by = [];
        foreach ($row as $k => $v) {
            $by[$k] = (int)$v;
        }
        $by['total'] = array_sum($by);

        return $by;
    }

    /**
     * Engages the worker pause for the given actor / maintenance session. The web
     * request is the initiator (it sets the flag and stamps the deadline, design
     * §4.2 ENTER→QUIESCE) and returns immediately; the drain is observed via
     * {@see status()} polling, never waited on synchronously.
     */
    public function pause(
        ?string $actorId = null,
        ?string $sessionId = null,
        int $deadlineSeconds = WorkerPauseGate::DEFAULT_DEADLINE_SECONDS,
    ): void {
        $this->gate->requestPause($actorId, $sessionId, $deadlineSeconds);
    }

    /** Releases the worker pause; workers resume on their next cycle. */
    public function resume(): void
    {
        $this->gate->release();
    }

    /**
     * Drain status for the GUI poller: whether a pause is engaged, the live
     * in-flight count (+ per-source breakdown), how many live workers have
     * acknowledged the pause, the deadline countdown, and the terminal flags the
     * poller stops on.
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $pause = $this->conn()->execute(<<<'SQL'
            SELECT paused,
                   CASE WHEN deadline_at IS NULL THEN NULL
                        ELSE GREATEST(0, EXTRACT(EPOCH FROM (deadline_at - now()))::int) END AS deadline_remaining,
                   (deadline_at IS NOT NULL AND now() >= deadline_at) AS deadline_passed
            FROM core.worker_pause WHERE id = true
            SQL,)->fetch('assoc');
        $paused = $pause !== false && (bool)$pause['paused'];
        // Displayed countdown (rounded is fine for the UI).
        $deadlineRemaining = $pause === false || $pause['deadline_remaining'] === null
            ? null
            : (int)$pause['deadline_remaining'];
        // Terminal flag derived from a DIRECT timestamp compare, not the rounded
        // countdown — otherwise timed_out could fire up to ~0.5s early.
        $deadlinePassed = $pause !== false && (bool)$pause['deadline_passed'];

        // Only heartbeats seen in the last minute count as live workers; dead rows
        // must not inflate the handshake. With the shared-flag model all workers
        // read the same pause row, so one live row reporting 'paused' is the
        // expected steady state.
        $workers = $this->conn()->execute(
            "SELECT count(*) FILTER (WHERE last_run_at > now() - interval '60 seconds') AS total, "
            . "count(*) FILTER (WHERE last_run_at > now() - interval '60 seconds' AND state = 'paused') AS paused "
            . 'FROM core.worker_heartbeats',
        )->fetch('assoc');

        $by = $this->inFlight();
        $inFlight = $by['total'];

        $drained = $paused && $inFlight === 0;
        $timedOut = $paused && $deadlinePassed && $inFlight > 0;

        return [
            'paused' => $paused,
            'in_flight' => $inFlight,
            'by_source' => $by,
            'workers_total' => (int)$workers['total'],
            'workers_paused' => (int)$workers['paused'],
            'deadline_seconds_remaining' => $deadlineRemaining,
            'drained' => $drained,
            'timed_out' => $timedOut,
            // The poller stops on `done`; `drained` vs `timed_out` tells it which.
            'done' => $drained || $timedOut,
        ];
    }
}
