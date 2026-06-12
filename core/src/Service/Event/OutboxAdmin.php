<?php
declare(strict_types=1);

namespace App\Service\Event;

use App\Audit\AuditLogger;
use App\Infrastructure\Uuid;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * Dead-letter management for the admin GUI (ch. 26.9.2).
 *
 * Lists failed events (`dead_letter`) and allows manual requeuing
 * (retry → `pending`, with counter/lock/error reset) or discarding
 * (`discarded`). Both actions are audited.
 */
class OutboxAdmin
{
    public function __construct(private ?AuditLogger $audit = null)
    {
        $this->audit ??= new AuditLogger();
    }

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /** @return array<string,int> Counts per status. */
    public function counts(): array
    {
        $rows = $this->conn()->execute(
            'SELECT status, count(*) AS n FROM event_outbox GROUP BY status',
        )->fetchAll('assoc');
        $out = ['pending' => 0, 'processing' => 0, 'done' => 0, 'dead_letter' => 0, 'discarded' => 0];
        foreach ($rows as $r) {
            $out[(string)$r['status']] = (int)$r['n'];
        }

        return $out;
    }

    /**
     * Dead-letter events (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function deadLetters(int $limit = 200): array
    {
        return array_values($this->conn()->execute(
            'SELECT id, created_at, contract_name, correlation_id, attempt_count, max_attempts, last_error '
            . "FROM event_outbox WHERE status = 'dead_letter' ORDER BY created_at DESC LIMIT :l",
            ['l' => $limit],
        )->fetchAll('assoc'));
    }

    /** Requeues a dead-letter event (retry). */
    public function retry(string $id): bool
    {
        // UUID guard: the ID comes from the URL; treat malformed values as
        // unknown instead of 22P02 -> 500 (cf. \App\Infrastructure\Uuid).
        if (!Uuid::isValid($id)) {
            return false;
        }
        $n = $this->conn()->execute(
            "UPDATE event_outbox SET status = 'pending', attempt_count = 0, available_at = now(), "
            . "locked_at = NULL, last_error = NULL WHERE id = :id AND status = 'dead_letter'",
            ['id' => $id],
        )->rowCount();
        if ($n > 0) {
            $this->audit->log('outbox.retry', 'event_outbox', $id, ['component' => 'core']);
        }

        return $n > 0;
    }

    /** Discards a dead-letter event permanently (discarded). */
    public function discard(string $id): bool
    {
        if (!Uuid::isValid($id)) {
            return false;
        }
        $n = $this->conn()->execute(
            "UPDATE event_outbox SET status = 'discarded', processed_at = now() "
            . "WHERE id = :id AND status = 'dead_letter'",
            ['id' => $id],
        )->rowCount();
        if ($n > 0) {
            $this->audit->log('outbox.discard', 'event_outbox', $id, ['component' => 'core']);
        }

        return $n > 0;
    }

    /** Requeues all dead-letter events. Returns the count. */
    public function retryAll(): int
    {
        $n = $this->conn()->execute(
            "UPDATE event_outbox SET status = 'pending', attempt_count = 0, available_at = now(), "
            . "locked_at = NULL, last_error = NULL WHERE status = 'dead_letter'",
        )->rowCount();
        if ($n > 0) {
            $this->audit->log('outbox.retry_all', 'event_outbox', null, ['component' => 'core', 'newValue' => ['count' => $n]]);
        }

        return $n;
    }
}
