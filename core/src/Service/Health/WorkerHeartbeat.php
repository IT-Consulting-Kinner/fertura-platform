<?php
declare(strict_types=1);

namespace App\Service\Health;

use Cake\Datasource\ConnectionManager;

/**
 * Writes/reads worker heartbeats (ch. 20.3): last run, status, detail per
 * CLI worker in core.worker_heartbeats. Source for worker freshness in the
 * health endpoint (ch. 20.2.1).
 */
class WorkerHeartbeat
{
    /**
     * Updates a worker's heartbeat (upsert).
     *
     * @param array<string, mixed>|null $detail
     */
    public static function beat(string $workerKey, string $status = 'ok', ?array $detail = null): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO worker_heartbeats (worker_key, last_run_at, last_status, detail) '
            . 'VALUES (:k, now(), :s, CAST(:d AS jsonb)) '
            . 'ON CONFLICT (worker_key) DO UPDATE SET '
            . 'last_run_at = now(), last_status = EXCLUDED.last_status, detail = EXCLUDED.detail',
            [
                'k' => $workerKey,
                's' => $status,
                'd' => $detail === null ? null : json_encode($detail),
            ],
        );
    }

    /**
     * All heartbeats with age in seconds.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return ConnectionManager::get('default')->execute(
            'SELECT worker_key, last_run_at, last_status, detail, '
            . 'EXTRACT(EPOCH FROM (now() - last_run_at))::int AS age_seconds '
            . 'FROM worker_heartbeats ORDER BY worker_key',
        )->fetchAll('assoc');
    }
}
