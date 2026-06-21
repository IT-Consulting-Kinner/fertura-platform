<?php
declare(strict_types=1);

namespace App\Service\Queue;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use stdClass;

/**
 * Default driver of the job-queue transport: Postgres table `job_queue`,
 * reserved with `FOR UPDATE SKIP LOCKED` (multiple consumers without collisions).
 * No external infrastructure required.
 */
class DbQueueTransport implements QueueTransportInterface
{
    private function conn(): Connection
    {
        // Narrow to the concrete Connection so execute() resolves (ConnectionInterface
        // omits it); same idiom as App\Infrastructure\Db.
        /** @var \Cake\Database\Connection $c */
        $c = ConnectionManager::get('default');

        return $c;
    }

    /** @param array<string,mixed> $payload */
    public function push(string $queue, array $payload): string
    {
        return (string)$this->conn()->execute(
            'INSERT INTO job_queue (queue, payload) VALUES (:q, CAST(:p AS jsonb)) RETURNING id',
            ['q' => $queue, 'p' => json_encode($payload === [] ? new stdClass() : $payload)],
        )->fetch('assoc')['id'];
    }

    public function reserve(string $queue, int $max = 1): array
    {
        $rows = $this->conn()->execute(
            <<<SQL
            WITH due AS (
                SELECT id FROM job_queue
                WHERE queue = :q AND status = 'ready'
                ORDER BY created_at
                FOR UPDATE SKIP LOCKED
                LIMIT :max
            )
            UPDATE job_queue j SET status = 'reserved', reserved_at = now()
            FROM due WHERE j.id = due.id
            RETURNING j.id, j.payload
            SQL,
            ['q' => $queue, 'max' => max(1, $max)],
        )->fetchAll('assoc');

        return array_values(array_map(static function (array $r): array {
            /** @var array<string,mixed> $payload */
            $payload = (array)(json_decode((string)$r['payload'], true) ?: []);

            return ['id' => (string)$r['id'], 'payload' => $payload];
        }, $rows));
    }

    public function ack(string $queue, string $id): void
    {
        $this->conn()->execute(
            'DELETE FROM job_queue WHERE queue = :q AND id = :id',
            ['q' => $queue, 'id' => $id],
        );
    }

    public function release(string $queue, string $id): void
    {
        $this->conn()->execute(
            "UPDATE job_queue SET status = 'ready', reserved_at = NULL WHERE queue = :q AND id = :id",
            ['q' => $queue, 'id' => $id],
        );
    }

    /**
     * Returns jobs stuck in 'reserved' — a consumer crashed between {@see reserve()}
     * and {@see ack()}/{@see release()} — back to 'ready' so another consumer can
     * claim them. Mirrors the outbox/webhook reclaim sweeps: a job whose `reserved_at`
     * is still within the window is assumed genuinely in flight and left alone. Spans
     * all queues. Returns the number reclaimed.
     */
    public function reclaimStuck(int $olderThanSeconds = 300): int
    {
        return $this->conn()->execute(
            "UPDATE job_queue SET status = 'ready', reserved_at = NULL "
            . "WHERE status = 'reserved' AND reserved_at < now() - (:secs || ' seconds')::interval",
            ['secs' => (string)max(1, $olderThanSeconds)],
        )->rowCount();
    }

    public function size(string $queue): int
    {
        return (int)$this->conn()->execute(
            "SELECT count(*) AS c FROM job_queue WHERE queue = :q AND status = 'ready'",
            ['q' => $queue],
        )->fetch('assoc')['c'];
    }

    public function name(): string
    {
        return 'db';
    }
}
