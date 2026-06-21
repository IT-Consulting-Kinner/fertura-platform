<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\System;

use App\Service\System\CriticalActionService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Critical-action state machine + crash recovery (Phase 4).
 *
 * Proves the lifecycle transitions, the "is anything in flight?" exit gate, and the
 * recovery sweep: a stale pre-mutation action aborts cleanly while a stale mutating
 * action goes to needs_manual_restore (never silently declared good), so a crash can
 * neither deadlock the exit nor hide an uncertain data state.
 */
class CriticalActionServiceTest extends TestCase
{
    private CriticalActionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new CriticalActionService();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        ConnectionManager::get('default')->execute('DELETE FROM core.critical_action');
    }

    private function seedStale(string $status): void
    {
        ConnectionManager::get('default')->execute(
            "INSERT INTO core.critical_action (type, status, heartbeat_at) VALUES ('test', :s, now() - interval '300 seconds')",
            ['s' => $status],
        );
    }

    private function statusOf(string $id): string
    {
        return (string)ConnectionManager::get('default')->execute(
            'SELECT status FROM core.critical_action WHERE id = :id',
            ['id' => $id],
        )->fetch('assoc')['status'];
    }

    public function testStartCreatesRunningAction(): void
    {
        $action = $this->svc->start('module_install', null, null);
        $this->assertSame('running', $action['status']);
        $this->assertNotEmpty($action['id']);
        $this->assertNotEmpty($action['fence_token']);
        $this->assertTrue($this->svc->hasNonTerminal());
        $this->assertSame(1, $this->svc->nonTerminalCount());
    }

    public function testTransitionToTerminalClearsTheExitGate(): void
    {
        $action = $this->svc->start('secret_rotate', null, null);
        $this->svc->markSucceeded($action['id']);

        $this->assertSame('succeeded', $this->statusOf($action['id']));
        $this->assertFalse($this->svc->hasNonTerminal());
        $this->assertSame(0, $this->svc->nonTerminalCount());

        $finished = ConnectionManager::get('default')->execute(
            'SELECT finished_at FROM core.critical_action WHERE id = :id',
            ['id' => $action['id']],
        )->fetch('assoc');
        $this->assertNotNull($finished['finished_at']);
    }

    public function testMarkFailedStoresMessage(): void
    {
        $action = $this->svc->start('trust_rotate', null, null);
        $this->svc->markFailed($action['id'], 'boom');
        $this->assertSame('failed', $this->statusOf($action['id']));
        $msg = ConnectionManager::get('default')->execute(
            'SELECT message FROM core.critical_action WHERE id = :id',
            ['id' => $action['id']],
        )->fetch('assoc');
        $this->assertSame('boom', $msg['message']);
    }

    public function testRecoverStaleAbortsPreMutationAndManualRestoresMutating(): void
    {
        $this->seedStale('quiescing'); // pre-mutation -> aborted
        $this->seedStale('backing_up'); // pre-mutation -> aborted
        $this->seedStale('running'); // mutating -> needs_manual_restore
        $this->seedStale('verifying'); // post -> needs_manual_restore
        $this->seedStale('rolling_back'); // rollback -> needs_manual_restore

        $this->assertSame(5, $this->svc->recoverStale(120));

        $conn = ConnectionManager::get('default');
        $aborted = (int)$conn->execute(
            "SELECT count(*) AS c FROM core.critical_action WHERE status = 'aborted'",
        )->fetch('assoc')['c'];
        $manual = (int)$conn->execute(
            "SELECT count(*) AS c FROM core.critical_action WHERE status = 'needs_manual_restore'",
        )->fetch('assoc')['c'];
        $this->assertSame(2, $aborted);
        $this->assertSame(3, $manual);
        // After recovery nothing blocks the exit.
        $this->assertFalse($this->svc->hasNonTerminal());
    }

    public function testRecoverStaleLeavesFreshActionsUntouched(): void
    {
        $action = $this->svc->start('module_install', null, null); // fresh heartbeat
        $this->assertSame(0, $this->svc->recoverStale(120));
        $this->assertSame('running', $this->statusOf($action['id']));
        $this->assertTrue($this->svc->hasNonTerminal());
    }

    public function testAttachBackupLinksBackupId(): void
    {
        $action = $this->svc->start('module_install', null, null);
        $backupId = '019eaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa';
        $this->svc->attachBackup($action['id'], $backupId);

        $row = ConnectionManager::get('default')->execute(
            'SELECT backup_id FROM core.critical_action WHERE id = :id',
            ['id' => $action['id']],
        )->fetch('assoc');
        $this->assertSame($backupId, $row['backup_id']);
    }

    public function testHeartbeatUnStalesAnAction(): void
    {
        $action = $this->svc->start('module_install', null, null);
        ConnectionManager::get('default')->execute(
            "UPDATE core.critical_action SET heartbeat_at = now() - interval '300 seconds' WHERE id = :id",
            ['id' => $action['id']],
        );
        $this->svc->heartbeat($action['id']); // fresh again
        $this->assertSame(0, $this->svc->recoverStale(120));
        $this->assertSame('running', $this->statusOf($action['id']));
    }

    public function testNonTerminalTransitionsKeepGateAndNullFinishedAt(): void
    {
        $action = $this->svc->start('module_install', null, null, 'quiescing'); // non-default $status
        $this->assertSame('quiescing', $action['status']);
        foreach (['backing_up', 'running', 'verifying'] as $next) {
            $this->svc->transition($action['id'], $next);
            $this->assertSame($next, $this->statusOf($action['id']));
            $this->assertTrue($this->svc->hasNonTerminal());
            $fin = ConnectionManager::get('default')->execute(
                'SELECT finished_at FROM core.critical_action WHERE id = :id',
                ['id' => $action['id']],
            )->fetch('assoc');
            $this->assertNull($fin['finished_at']);
        }
        $this->svc->markSucceeded($action['id']);
        $this->assertFalse($this->svc->hasNonTerminal());
    }

    public function testTransitionIsNoOpOnAlreadyTerminalAction(): void
    {
        // The fence-precursor guard: a zombie must not overwrite a finalised state.
        $action = $this->svc->start('module_install', null, null);
        $this->svc->markFailed($action['id'], 'boom');
        $this->svc->markSucceeded($action['id']); // ignored
        $this->assertSame('failed', $this->statusOf($action['id']));
    }

    public function testRecoverStaleRespectsTheWindowBoundary(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute("INSERT INTO core.critical_action (type, status, heartbeat_at) VALUES ('inside', 'running', now() - interval '100 seconds')");
        $conn->execute("INSERT INTO core.critical_action (type, status, heartbeat_at) VALUES ('outside', 'running', now() - interval '130 seconds')");

        $this->assertSame(1, $this->svc->recoverStale(120)); // only the 130s row
        $this->assertSame('running', $this->typeStatus('inside'));
        $this->assertSame('needs_manual_restore', $this->typeStatus('outside'));
    }

    public function testRecoverStalePreservesAndAppendsMessage(): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO core.critical_action (type, status, message, heartbeat_at) '
            . "VALUES ('test', 'running', 'phase 2 of 3', now() - interval '300 seconds')",
        );
        $this->svc->recoverStale(120);
        $msg = (string)ConnectionManager::get('default')->execute(
            "SELECT message FROM core.critical_action WHERE type = 'test'",
        )->fetch('assoc')['message'];
        $this->assertStringContainsString('phase 2 of 3', $msg);
        $this->assertStringContainsString('[recovered: stale heartbeat]', $msg);
    }

    private function typeStatus(string $type): string
    {
        return (string)ConnectionManager::get('default')->execute(
            'SELECT status FROM core.critical_action WHERE type = :t',
            ['t' => $type],
        )->fetch('assoc')['status'];
    }
}
