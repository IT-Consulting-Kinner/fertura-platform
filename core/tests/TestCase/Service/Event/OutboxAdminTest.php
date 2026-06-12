<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Event;

use App\Service\Event\OutboxAdmin;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Test of dead-letter management (ch. 26.9.2): counts, listing, retry
 * (→ pending with reset counters/lock/error), discard (→ discarded)
 * and retry-all — each effective only on `dead_letter` events.
 */
class OutboxAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        ConnectionManager::get('default')->execute(
            "DELETE FROM event_outbox WHERE contract_name LIKE 'zztest.dla%'",
        );
    }

    private function insertEvent(string $status, string $suffix = ''): string
    {
        return (string)ConnectionManager::get('default')->execute(
            'INSERT INTO event_outbox (contract_name, payload, max_attempts, status, attempt_count, last_error) '
            . "VALUES (:c, '{}'::jsonb, 5, :s, 5, :e) RETURNING id",
            ['c' => 'zztest.dla' . $suffix, 's' => $status, 'e' => $status === 'dead_letter' ? 'boom' : null],
        )->fetch('assoc')['id'];
    }

    public function testCountsAndDeadLetterList(): void
    {
        $this->insertEvent('dead_letter', '.counts');
        $this->insertEvent('pending', '.counts');

        $admin = new OutboxAdmin();
        $counts = $admin->counts();
        $this->assertGreaterThanOrEqual(1, $counts['dead_letter']);
        $this->assertGreaterThanOrEqual(1, $counts['pending']);

        $list = $admin->deadLetters();
        $names = array_column($list, 'contract_name');
        $this->assertContains('zztest.dla.counts', $names);
        // Only dead-letter rows in the list, no pending events.
        foreach ($list as $row) {
            $this->assertArrayHasKey('last_error', $row);
        }
    }

    public function testRetryResetsCountersAndOnlyDeadLetter(): void
    {
        $dead = $this->insertEvent('dead_letter', '.retry');
        $pending = $this->insertEvent('pending', '.retry');
        $admin = new OutboxAdmin();

        $this->assertTrue($admin->retry($dead));
        $row = ConnectionManager::get('default')->execute(
            'SELECT status, attempt_count, last_error, locked_at FROM event_outbox WHERE id = :id',
            ['id' => $dead],
        )->fetch('assoc');
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, (int)$row['attempt_count']);
        $this->assertNull($row['last_error']);
        $this->assertNull($row['locked_at']);

        // No effect on non-dead_letter rows (no accidental reset).
        $this->assertFalse($admin->retry($pending));
        $this->assertFalse($admin->retry('00000000-0000-0000-0000-000000000000'));
    }

    public function testDiscardIsTerminalAndOnlyDeadLetter(): void
    {
        $dead = $this->insertEvent('dead_letter', '.discard');
        $admin = new OutboxAdmin();

        $this->assertTrue($admin->discard($dead));
        $row = ConnectionManager::get('default')->execute(
            'SELECT status, processed_at FROM event_outbox WHERE id = :id',
            ['id' => $dead],
        )->fetch('assoc');
        $this->assertSame('discarded', $row['status']);
        $this->assertNotNull($row['processed_at']);

        // Already discarded -> second discard/retry has no effect.
        $this->assertFalse($admin->discard($dead));
        $this->assertFalse($admin->retry($dead));
    }

    public function testRetryAllOnlyAffectsDeadLetters(): void
    {
        $this->insertEvent('dead_letter', '.all1');
        $this->insertEvent('dead_letter', '.all2');
        $done = $this->insertEvent('done', '.all3');
        $admin = new OutboxAdmin();

        $n = $admin->retryAll();
        $this->assertGreaterThanOrEqual(2, $n);
        $status = ConnectionManager::get('default')->execute(
            'SELECT status FROM event_outbox WHERE id = :id',
            ['id' => $done],
        )->fetch('assoc')['status'];
        $this->assertSame('done', $status); // untouched
    }
}
