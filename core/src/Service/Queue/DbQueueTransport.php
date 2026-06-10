<?php
declare(strict_types=1);

namespace App\Service\Queue;

use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;

/**
 * Default-Treiber des Job-Queue-Transports: Postgres-Tabelle `job_queue`,
 * reserviert mit `FOR UPDATE SKIP LOCKED` (mehrere Consumer kollisionsfrei).
 * Keine externe Infrastruktur nötig.
 */
class DbQueueTransport implements QueueTransportInterface
{
    private function conn(): ConnectionInterface
    {
        return ConnectionManager::get('default');
    }

    public function push(string $queue, array $payload): string
    {
        return (string)$this->conn()->execute(
            'INSERT INTO job_queue (queue, payload) VALUES (:q, CAST(:p AS jsonb)) RETURNING id',
            ['q' => $queue, 'p' => json_encode($payload === [] ? new \stdClass() : $payload)],
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

        return array_map(static fn (array $r): array => [
            'id' => (string)$r['id'],
            'payload' => (array)(json_decode((string)$r['payload'], true) ?: []),
        ], $rows);
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
