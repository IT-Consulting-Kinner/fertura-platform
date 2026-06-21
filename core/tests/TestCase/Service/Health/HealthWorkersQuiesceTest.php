<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Health;

use App\Service\Health\HealthService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use ReflectionMethod;

/**
 * Quiesce-aware worker health (A4): during an active maintenance window the worker
 * intentionally skips {@see \App\Service\Schedule\ScheduledTaskRunner::tick()}, so the
 * per-task `sched:*` heartbeats go stale BY DESIGN. That staleness must not drag the
 * overall worker health to "degraded" — but a real worker crash (the worker's own
 * heartbeat going stale) and a prior `error` still surface.
 *
 * checkWorkers() is private; it is exercised in isolation via reflection so the test
 * does not have to stand up every other health subsystem.
 */
class HealthWorkersQuiesceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
        // Own the heartbeat table for a deterministic overall-status assertion (no
        // real worker runs in the test env, so it is effectively test-seeded).
        $conn->execute('DELETE FROM core.worker_heartbeats');
        // A leaked OPEN session would 503 the rest of the suite via the gate.
        $conn->execute('DELETE FROM core.maintenance_session');
    }

    private function seedHeartbeat(string $key, string $status, int $ageSeconds): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO worker_heartbeats (worker_key, last_run_at, last_status) '
            . "VALUES (:k, now() - (:age || ' seconds')::interval, :s)",
            ['k' => $key, 'age' => (string)$ageSeconds, 's' => $status],
        );
    }

    private function engageMaintenance(): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO core.maintenance_session (actor_user_id, allow_token_hash) VALUES (NULL, :h)',
            ['h' => hash('sha256', 'x')],
        );
    }

    /** @return array<string,mixed> */
    private function checkWorkers(): array
    {
        $method = new ReflectionMethod(HealthService::class, 'checkWorkers');
        $method->setAccessible(true);
        /** @var array<string,mixed> $result */
        $result = $method->invoke(new HealthService());

        return $result;
    }

    public function testStaleSchedDegradesWhenNotInMaintenance(): void
    {
        $this->seedHeartbeat('sched:zztest_a', 'ok', 600); // > 120s default threshold
        $res = $this->checkWorkers();

        $this->assertSame('degraded', $res['status']);
        $worker = $res['detail']['workers']['sched:zztest_a'];
        $this->assertTrue($worker['stale']);
        $this->assertFalse($worker['pause_suppressed']);
    }

    public function testStaleSchedSuppressedDuringMaintenance(): void
    {
        $this->seedHeartbeat('sched:zztest_a', 'ok', 600);
        $this->engageMaintenance();
        $res = $this->checkWorkers();

        $this->assertSame('up', $res['status']); // sched staleness is expected here
        $worker = $res['detail']['workers']['sched:zztest_a'];
        $this->assertTrue($worker['stale']); // still reported as stale (visibility)...
        $this->assertTrue($worker['pause_suppressed']); // ...but not counted as degraded
        $this->assertTrue($worker['paused']);
    }

    public function testStaleNonSchedWorkerStillDegradesDuringMaintenance(): void
    {
        // The worker's OWN heartbeat keeps refreshing while paused, so if it IS stale
        // that is a real crash and must still surface — only sched:* is suppressed.
        $this->seedHeartbeat('outbox', 'ok', 600);
        $this->engageMaintenance();
        $res = $this->checkWorkers();

        $this->assertSame('degraded', $res['status']);
        $this->assertFalse($res['detail']['workers']['outbox']['pause_suppressed']);
    }

    public function testErroredSchedStillDownDuringMaintenance(): void
    {
        // A prior error is not a staleness condition; the pause must not mask it.
        $this->seedHeartbeat('sched:zztest_err', 'error', 600);
        $this->engageMaintenance();
        $res = $this->checkWorkers();

        $this->assertSame('down', $res['status']);
    }

    public function testPausedWorkerIsReportedPausedAndNotDegraded(): void
    {
        // A6 visibility: the quiesce handshake writes state='paused' AND keeps the
        // heartbeat fresh, so checkWorkers() — and thus the health report() that calls
        // it — must surface the worker as paused + up, never as a fault. (The existing
        // pause integration test only asserts the raw heartbeat row; this asserts the
        // health aggregation that operators actually read.)
        ConnectionManager::get('default')->execute(
            'INSERT INTO worker_heartbeats (worker_key, last_run_at, last_status, state) '
            . "VALUES ('outbox', now(), 'ok', 'paused')",
        );
        $res = $this->checkWorkers();

        $this->assertSame('up', $res['status']);
        $this->assertTrue($res['detail']['workers']['outbox']['paused']);
        $this->assertSame('paused', $res['detail']['workers']['outbox']['state']);
    }
}
