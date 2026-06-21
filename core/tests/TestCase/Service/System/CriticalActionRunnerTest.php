<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\System;

use App\Service\Backup\BackupService;
use App\Service\System\CriticalActionHandler;
use App\Service\System\CriticalActionRegistry;
use App\Service\System\CriticalActionRunner;
use App\Service\System\CriticalActionService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use RuntimeException;

/**
 * The critical-action runner (Phase 6): drives a queued action through
 * backup → execute → verify → succeed, with rollback on failure and escalation to
 * needs_manual_restore when the rollback itself fails. Driven with a stub handler
 * and a stub backup so the orchestration + state machine are tested without a real
 * (heavy) install or pg_dump. The maintenance session is cleaned around each test so
 * a leak cannot 503 the rest of the suite via the live gate.
 */
class CriticalActionRunnerTest extends TestCase
{
    private const ACTOR = '11111111-1111-7111-8111-111111111111';

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
        foreach (['critical_action', 'event_outbox', 'webhook_deliveries', 'webhook_subscriptions', 'job_queue', 'module_install_jobs'] as $t) {
            $conn->execute(str_starts_with($t, 'critical') ? 'DELETE FROM core.critical_action' : "DELETE FROM $t");
        }
        $conn->execute('DELETE FROM core.maintenance_session');
    }

    private function engage(): string
    {
        return (string)ConnectionManager::get('default')->execute(
            'INSERT INTO core.maintenance_session (actor_user_id, allow_token_hash) VALUES (:a, :h) RETURNING id',
            ['a' => self::ACTOR, 'h' => hash('sha256', 'x')],
        )->fetch('assoc')['id'];
    }

    private function runner(StubActionHandler $handler): CriticalActionRunner
    {
        return new CriticalActionRunner(
            new CriticalActionService(),
            new CriticalActionRegistry([$handler]),
            null,
            null,
            new StubBackupService(),
        );
    }

    private function statusOf(string $id): string
    {
        return (string)ConnectionManager::get('default')->execute(
            'SELECT status FROM core.critical_action WHERE id = :id',
            ['id' => $id],
        )->fetch('assoc')['status'];
    }

    public function testNothingRunsWithoutAMaintenanceSession(): void
    {
        $this->assertNull($this->runner(new StubActionHandler())->tick());
    }

    public function testWaitsForDrainBeforeRunning(): void
    {
        $session = $this->engage();
        (new CriticalActionService())->enqueue('stub.test', $session, self::ACTOR, []);
        // Outstanding in-flight work -> the runner must not claim/run yet.
        ConnectionManager::get('default')->execute(
            "INSERT INTO event_outbox (contract_name, status) VALUES ('runner.test', 'processing')",
        );

        $this->assertNull($this->runner(new StubActionHandler())->tick());
    }

    public function testHappyPathBackupExecuteVerifySucceed(): void
    {
        $session = $this->engage();
        $action = (new CriticalActionService())->enqueue('stub.test', $session, self::ACTOR, ['k' => 'v']);
        $handler = new StubActionHandler();

        $this->assertSame($action['id'], $this->runner($handler)->tick());
        $this->assertSame('succeeded', $this->statusOf($action['id']));
        $this->assertSame(['execute', 'verify'], $handler->calls);
        // The pre-action backup was linked.
        $row = ConnectionManager::get('default')->execute(
            'SELECT backup_id FROM core.critical_action WHERE id = :id',
            ['id' => $action['id']],
        )->fetch('assoc');
        $this->assertNotNull($row['backup_id']);
    }

    public function testRollsBackOnExecuteFailure(): void
    {
        $session = $this->engage();
        $action = (new CriticalActionService())->enqueue('stub.test', $session, self::ACTOR, []);
        $handler = new StubActionHandler();
        $handler->executeThrows = true;

        $this->runner($handler)->tick();
        $this->assertSame('failed', $this->statusOf($action['id']));
        $this->assertContains('rollback', $handler->calls);
        $this->assertNotContains('verify', $handler->calls);
    }

    public function testRollsBackOnVerifyFailure(): void
    {
        $session = $this->engage();
        $action = (new CriticalActionService())->enqueue('stub.test', $session, self::ACTOR, []);
        $handler = new StubActionHandler();
        $handler->verifyOk = false;

        $this->runner($handler)->tick();
        $this->assertSame('failed', $this->statusOf($action['id']));
        $this->assertSame(['execute', 'verify', 'rollback'], $handler->calls);
    }

    public function testNeedsManualRestoreWhenRollbackAlsoFails(): void
    {
        $session = $this->engage();
        $action = (new CriticalActionService())->enqueue('stub.test', $session, self::ACTOR, []);
        $handler = new StubActionHandler();
        $handler->executeThrows = true;
        $handler->rollbackThrows = true;

        $this->runner($handler)->tick();
        $this->assertSame('needs_manual_restore', $this->statusOf($action['id']));
    }

    public function testUnknownTypeFails(): void
    {
        $session = $this->engage();
        $action = (new CriticalActionService())->enqueue('no.such.type', $session, self::ACTOR, []);

        $this->runner(new StubActionHandler())->tick();
        $this->assertSame('failed', $this->statusOf($action['id']));
    }
}

/** Records calls; configurable to fail at execute/rollback/verify. */
class StubActionHandler implements CriticalActionHandler
{
    /**
     * @var list<string>
     */
    public array $calls = [];
    public bool $executeThrows = false;
    public bool $rollbackThrows = false;
    public bool $verifyOk = true;

    public function type(): string
    {
        return 'stub.test';
    }

    public function execute(array $payload): void
    {
        $this->calls[] = 'execute';
        if ($this->executeThrows) {
            throw new RuntimeException('exec-boom');
        }
    }

    public function verify(array $payload): array
    {
        $this->calls[] = 'verify';

        return ['ok' => $this->verifyOk, 'reason' => $this->verifyOk ? null : 'stub-bad'];
    }

    public function rollback(array $payload): void
    {
        $this->calls[] = 'rollback';
        if ($this->rollbackThrows) {
            throw new RuntimeException('rollback-boom');
        }
    }
}

/** Backup stub: no real pg_dump — returns a fixed id. */
class StubBackupService extends BackupService
{
    public function context(string $source, ?string $actor = null): static
    {
        return $this;
    }

    public function createLocked(?string $note, ?string $actorId): string
    {
        return '019ebbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb';
    }
}
