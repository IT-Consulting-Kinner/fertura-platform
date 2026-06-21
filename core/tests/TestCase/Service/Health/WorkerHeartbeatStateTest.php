<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Health;

use App\Service\Health\WorkerHeartbeat;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * The quiesce handshake state on core.worker_heartbeats — Phase 2.
 *
 * The critical guarantee: {@see WorkerHeartbeat::markState()} sets `state` without
 * touching `last_status`, and a subsequent normal {@see WorkerHeartbeat::beat()}
 * leaves `state` intact — otherwise every cycle would erase a 'paused' handshake.
 */
class WorkerHeartbeatStateTest extends TestCase
{
    private const KEY = 'hb-state-test';

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
        ConnectionManager::get('default')->execute(
            'DELETE FROM worker_heartbeats WHERE worker_key = :k',
            ['k' => self::KEY],
        );
    }

    private function fetch(): array
    {
        return ConnectionManager::get('default')->execute(
            'SELECT last_status, state FROM worker_heartbeats WHERE worker_key = :k',
            ['k' => self::KEY],
        )->fetch('assoc') ?: [];
    }

    public function testMarkStateSetsStateOnFreshRow(): void
    {
        WorkerHeartbeat::markState(self::KEY, 'paused');
        $row = $this->fetch();
        $this->assertSame('paused', $row['state']);
        $this->assertSame('ok', $row['last_status']);
    }

    public function testBeatDoesNotClobberPausedState(): void
    {
        WorkerHeartbeat::markState(self::KEY, 'paused');
        // A normal cycle heartbeat must NOT reset the handshake back to running.
        WorkerHeartbeat::beat(self::KEY, 'ok', ['processed' => 0]);
        $this->assertSame('paused', $this->fetch()['state']);
    }

    public function testMarkStateRunningClearsPause(): void
    {
        WorkerHeartbeat::markState(self::KEY, 'paused');
        WorkerHeartbeat::markState(self::KEY, 'running');
        $this->assertSame('running', $this->fetch()['state']);
    }

    public function testMarkStatePreservesPriorLastStatus(): void
    {
        // The reverse of the clobber guard: markState must not reset last_status.
        WorkerHeartbeat::beat(self::KEY, 'warn', ['duration_ms' => 42]);
        WorkerHeartbeat::markState(self::KEY, 'paused');
        $row = $this->fetch();
        $this->assertSame('paused', $row['state']);
        $this->assertSame('warn', $row['last_status']);
    }

    public function testAllExposesState(): void
    {
        WorkerHeartbeat::markState(self::KEY, 'paused');
        $rows = array_filter(WorkerHeartbeat::all(), static fn($r) => $r['worker_key'] === self::KEY);
        $this->assertCount(1, $rows);
        $this->assertSame('paused', array_values($rows)[0]['state']);
    }
}
