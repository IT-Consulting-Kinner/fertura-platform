<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\System;

use App\Service\System\WorkerPauseGate;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Worker-pause signal (core.worker_pause) — Phase 2.
 *
 * Proves the uncached read reflects writes immediately, that engaging stamps the
 * server-side deadline, and that releasing clears it. The singleton row is reset
 * around each test rather than deleted (the migration seeds exactly one row).
 */
class WorkerPauseGateTest extends TestCase
{
    private WorkerPauseGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new WorkerPauseGate();
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();
        parent::tearDown();
    }

    private function reset(): void
    {
        ConnectionManager::get('default')->execute(
            'UPDATE core.worker_pause SET paused = false, requested_at = NULL, deadline_at = NULL, '
            . 'actor_user_id = NULL, maintenance_session_id = NULL WHERE id = true',
        );
    }

    public function testDefaultsToNotPaused(): void
    {
        $this->assertFalse($this->gate->isPaused());
    }

    public function testRequestPauseEngagesAndStampsDeadline(): void
    {
        $actor = '11111111-1111-7111-8111-111111111111';
        $session = '22222222-2222-7222-8222-222222222222';
        $this->gate->requestPause($actor, $session, 120);

        $this->assertTrue($this->gate->isPaused());
        $row = $this->gate->pauseRow();
        $this->assertNotNull($row);
        $this->assertTrue((bool)$row['paused']);
        $this->assertSame($actor, $row['actor_user_id']);
        $this->assertSame($session, $row['maintenance_session_id']);
        $this->assertNotNull($row['deadline_at']);

        // The deadline is in the future (≈120s out).
        $remaining = ConnectionManager::get('default')->execute(
            'SELECT EXTRACT(EPOCH FROM (deadline_at - now()))::int AS r FROM core.worker_pause WHERE id = true',
        )->fetch('assoc');
        $this->assertGreaterThan(100, (int)$remaining['r']);
        $this->assertLessThanOrEqual(120, (int)$remaining['r']);
    }

    public function testReleaseResumes(): void
    {
        $this->gate->requestPause(null, null, 120);
        $this->assertTrue($this->gate->isPaused());

        $this->gate->release();
        $this->assertFalse($this->gate->isPaused());
        $row = $this->gate->pauseRow();
        $this->assertNotNull($row);
        $this->assertNull($row['deadline_at']);
    }
}
