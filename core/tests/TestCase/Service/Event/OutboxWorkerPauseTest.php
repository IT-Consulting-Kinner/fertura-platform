<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Event;

use App\Service\Event\OutboxWorker;
use App\Service\System\WorkerPauseGate;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * The worker pause-gate in {@see OutboxWorker::run()} — Phase 2.
 *
 * Drives the real run loop with the pause engaged and asserts the observable
 * effects: the worker reports the paused handshake (worker_heartbeats.state) and
 * does NOT process a queued event. The loop is stopped from the logger the instant
 * it announces the pause, so exactly one paused iteration runs (pollMs is tiny).
 * Only the paused branch is exercised here — it short-circuits before any of the
 * heavy cycle work, so running the loop in-process is safe.
 */
class OutboxWorkerPauseTest extends TestCase
{
    private WorkerPauseGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new WorkerPauseGate();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute("DELETE FROM event_outbox WHERE contract_name = 'worker.pause.test'");
        $conn->execute("DELETE FROM worker_heartbeats WHERE worker_key = 'outbox'");
        $conn->execute('UPDATE core.worker_pause SET paused = false, deadline_at = NULL WHERE id = true');
        $conn->execute("DELETE FROM core.critical_action WHERE type = 'worker.recovery.test'");
    }

    public function testPausedWorkerReportsStateAndSkipsProcessing(): void
    {
        $conn = ConnectionManager::get('default');
        // A due event the worker would normally claim immediately.
        $conn->execute("INSERT INTO event_outbox (contract_name) VALUES ('worker.pause.test')");
        $this->gate->requestPause(null, null, 120);

        // Stop the loop the moment it logs the pause -> exactly one paused cycle.
        $worker = null;
        $logger = function (string $msg) use (&$worker): void {
            if ($worker !== null && str_contains($msg, 'pausiert')) {
                $worker->stop();
            }
        };
        $worker = new OutboxWorker(null, 50, 5, 300, 50, $logger);
        $worker->run();

        // Handshake written.
        $beat = $conn->execute(
            "SELECT state FROM worker_heartbeats WHERE worker_key = 'outbox'",
        )->fetch('assoc');
        $this->assertNotFalse($beat);
        $this->assertSame('paused', $beat['state']);

        // The event was NOT processed (still pending).
        $status = $conn->execute(
            "SELECT status FROM event_outbox WHERE contract_name = 'worker.pause.test'",
        )->fetch('assoc');
        $this->assertSame('pending', $status['status']);
    }

    public function testWorkerResumesWhenPauseIsReleased(): void
    {
        $conn = ConnectionManager::get('default');
        $this->gate->requestPause(null, null, 120);

        // Release on the first pause log so the next cycle resumes; stop once the
        // worker announces it has resumed. Proves run() wires release ->
        // markState('running') -> log, the integration path no unit test covers.
        $worker = null;
        $resumed = false;
        $logger = function (string $msg) use (&$worker, &$resumed): void {
            if ($worker === null) {
                return;
            }
            if (str_contains($msg, 'pausiert')) {
                $this->gate->release();
            } elseif (str_contains($msg, 'fortgesetzt')) {
                $resumed = true;
                $worker->stop();
            }
        };
        $worker = new OutboxWorker(null, 50, 5, 300, 50, $logger);
        $worker->run();

        $this->assertTrue($resumed, 'worker never logged the resume');
        $beat = $conn->execute(
            "SELECT state FROM worker_heartbeats WHERE worker_key = 'outbox'",
        )->fetch('assoc');
        $this->assertSame('running', $beat['state']);
    }

    public function testStartupSweepRecoversStaleCriticalAction(): void
    {
        // The worker is the headless crash-recovery backstop: a critical action whose
        // process died (stale heartbeat) must be swept to a terminal state at startup,
        // even when no operator visits the GUI. Bound run() via the pause path.
        $conn = ConnectionManager::get('default');
        $conn->execute(
            'INSERT INTO core.critical_action (type, status, heartbeat_at) '
            . "VALUES ('worker.recovery.test', 'running', now() - interval '300 seconds')",
        );
        $this->gate->requestPause(null, null, 120);

        $worker = null;
        $logger = function (string $msg) use (&$worker): void {
            if ($worker !== null && str_contains($msg, 'pausiert')) {
                $worker->stop();
            }
        };
        $worker = new OutboxWorker(null, 50, 5, 300, 50, $logger);
        $worker->run();

        // Startup recoverStale() runs before the loop -> the action is recovered.
        $status = $conn->execute(
            "SELECT status FROM core.critical_action WHERE type = 'worker.recovery.test'",
        )->fetch('assoc');
        $this->assertSame('needs_manual_restore', $status['status']);
    }
}
