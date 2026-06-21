<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\System;

use App\Service\Module\ModuleInstallJobService;
use App\Service\System\QuiesceService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Quiesce drain accounting (QuiesceService) — Phase 2.
 *
 * Verifies inFlight() aggregates every in-flight source and excludes
 * backoff-delayed (future) pending work, and that status() computes the
 * drained / timed_out / done terminal flags the GUI poller stops on. The
 * operational source tables are cleared around each test for a deterministic
 * zero baseline (these tables are not fixture-managed).
 */
class QuiesceServiceTest extends TestCase
{
    private const VALID_UUID = '11111111-1111-7111-8111-111111111111';

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
        $tables = ['event_outbox', 'webhook_deliveries', 'webhook_subscriptions', 'job_queue', 'module_install_jobs', 'worker_heartbeats'];
        foreach ($tables as $t) {
            $conn->execute("DELETE FROM $t");
        }
        $conn->execute(
            'UPDATE core.worker_pause SET paused = false, requested_at = NULL, deadline_at = NULL, '
            . 'actor_user_id = NULL, maintenance_session_id = NULL WHERE id = true',
        );
    }

    private function seedBeat(string $key, string $state, string $ageInterval): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO worker_heartbeats (worker_key, last_run_at, last_status, state) '
            . "VALUES (:k, now() - (:age)::interval, 'ok', :st)",
            ['k' => $key, 'age' => $ageInterval, 'st' => $state],
        );
    }

    public function testInFlightAggregatesAllSourcesAndExcludesFuturePending(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute("INSERT INTO event_outbox (contract_name, status) VALUES ('quiesce.test', 'processing')");
        $conn->execute("INSERT INTO event_outbox (contract_name) VALUES ('quiesce.test')"); // pending, due now
        // Backoff-delayed retry — pending but NOT due: must be excluded.
        $conn->execute("INSERT INTO event_outbox (contract_name, available_at) VALUES ('quiesce.test', now() + interval '1 hour')");
        $conn->execute("INSERT INTO job_queue (queue, status) VALUES ('quiesce-test', 'reserved')");
        $jobs = new ModuleInstallJobService();
        $jobs->enqueue('/tmp/q.zip', 'q.zip', 'in_process', null);
        $jobs->claimNext(); // -> running (a queued job is not counted as in-flight)
        $sid = $conn->execute(
            "INSERT INTO webhook_subscriptions (name, url) VALUES ('quiesce-test', 'http://example.test') RETURNING id",
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO webhook_deliveries (subscription_id, event_id, event_name, status) '
            . "VALUES (:s, core.uuid_generate_v7(), 'quiesce.test', 'delivering')",
            ['s' => $sid],
        );

        $by = (new QuiesceService())->inFlight();
        $this->assertSame(1, $by['outbox_processing']);
        $this->assertSame(1, $by['outbox_due']);
        $this->assertSame(0, $by['webhook_due']);
        $this->assertSame(1, $by['webhook_delivering']);
        $this->assertSame(1, $by['install_jobs']);
        $this->assertSame(1, $by['jobs_reserved']);
        // 5 in-flight; the future-dated pending event is not counted.
        $this->assertSame(5, $by['total']);
    }

    public function testStatusDrainedWhenNothingInFlight(): void
    {
        $svc = new QuiesceService();
        $svc->pause(self::VALID_UUID, null, 120);

        $st = $svc->status();
        $this->assertTrue($st['paused']);
        $this->assertSame(0, $st['in_flight']);
        $this->assertTrue($st['drained']);
        $this->assertFalse($st['timed_out']);
        $this->assertTrue($st['done']);
        $this->assertNotNull($st['deadline_seconds_remaining']);
        $this->assertGreaterThan(0, $st['deadline_seconds_remaining']);
    }

    public function testStatusTimedOutWhenDeadlinePassesWithWorkOutstanding(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute("INSERT INTO event_outbox (contract_name, status) VALUES ('quiesce.test', 'processing')");

        $svc = new QuiesceService();
        $svc->pause(null, null, 120);
        // Force the deadline into the past.
        $conn->execute("UPDATE core.worker_pause SET deadline_at = now() - interval '1 second' WHERE id = true");

        $st = $svc->status();
        $this->assertTrue($st['paused']);
        $this->assertSame(1, $st['in_flight']);
        $this->assertSame(0, $st['deadline_seconds_remaining']);
        $this->assertTrue($st['timed_out']);
        $this->assertFalse($st['drained']);
        $this->assertTrue($st['done']);
    }

    public function testStatusWhenIdleIsNotDone(): void
    {
        $st = (new QuiesceService())->status();
        $this->assertFalse($st['paused']);
        $this->assertFalse($st['drained']);
        $this->assertFalse($st['timed_out']);
        $this->assertFalse($st['done']);
    }

    public function testInFlightCountsDueWebhookAndExcludesFuture(): void
    {
        $conn = ConnectionManager::get('default');
        $sid = $conn->execute(
            "INSERT INTO webhook_subscriptions (name, url) VALUES ('quiesce-test', 'http://example.test') RETURNING id",
        )->fetch('assoc')['id'];
        // One delivery due now (counted) and one backoff-delayed (excluded).
        $conn->execute(
            'INSERT INTO webhook_deliveries (subscription_id, event_id, event_name, status, available_at) '
            . "VALUES (:s, core.uuid_generate_v7(), 'quiesce.test', 'pending', now())",
            ['s' => $sid],
        );
        $conn->execute(
            'INSERT INTO webhook_deliveries (subscription_id, event_id, event_name, status, available_at) '
            . "VALUES (:s, core.uuid_generate_v7(), 'quiesce.test', 'pending', now() + interval '1 hour')",
            ['s' => $sid],
        );

        $by = (new QuiesceService())->inFlight();
        $this->assertSame(1, $by['webhook_due']);
        $this->assertSame(0, $by['webhook_delivering']);
    }

    public function testInFlightCountsRunningInstallJob(): void
    {
        $jobs = new ModuleInstallJobService();
        $jobs->enqueue('/tmp/a.zip', 'a.zip', 'in_process', null);
        $jobs->claimNext(); // queued -> running

        // Covers the 'running' arm of status IN ('queued','running').
        $this->assertSame(1, (new QuiesceService())->inFlight()['install_jobs']);
    }

    public function testStatusCountsLiveWorkersOnly(): void
    {
        $this->seedBeat('q-fresh-paused', 'paused', '0 seconds');
        $this->seedBeat('q-fresh-running', 'running', '5 seconds');
        $this->seedBeat('q-stale-paused', 'paused', '120 seconds'); // older than the 60s window

        $st = (new QuiesceService())->status();
        $this->assertSame(2, $st['workers_total']); // the stale row is not counted
        $this->assertSame(1, $st['workers_paused']);
    }
}
