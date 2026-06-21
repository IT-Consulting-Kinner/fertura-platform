<?php
declare(strict_types=1);

namespace App\Service\Health;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * Writes/reads worker heartbeats (ch. 20.3): last run, status, detail per
 * CLI worker in core.worker_heartbeats. Source for worker freshness in the
 * health endpoint (ch. 20.2.1).
 */
class WorkerHeartbeat
{
    private static function conn(): Connection
    {
        /** @var \Cake\Database\Connection $c */
        $c = ConnectionManager::get('default');

        return $c;
    }

    /**
     * Updates a worker's heartbeat (upsert).
     *
     * @param array<string, mixed>|null $detail
     */
    public static function beat(string $workerKey, string $status = 'ok', ?array $detail = null): void
    {
        self::conn()->execute(
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
     * Records the quiesce handshake state ('running'|'paused') for a worker
     * WITHOUT touching last_status. Deliberately separate from {@see beat()}: the
     * pause-gate writes 'paused' once on entering the pause, and because beat()'s
     * ON CONFLICT leaves `state` alone, a normal cycle never resets it. last_run_at
     * is refreshed so a paused worker still reads as live (not overdue). On the
     * first INSERT a placeholder 'ok' last_status is supplied to satisfy NOT NULL;
     * thereafter only state + last_run_at change.
     */
    public static function markState(string $workerKey, string $state): void
    {
        self::conn()->execute(
            'INSERT INTO worker_heartbeats (worker_key, last_run_at, last_status, state) '
            . "VALUES (:k, now(), 'ok', :st) "
            . 'ON CONFLICT (worker_key) DO UPDATE SET state = EXCLUDED.state, last_run_at = now()',
            ['k' => $workerKey, 'st' => $state],
        );
    }

    /**
     * All heartbeats with age in seconds.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = self::conn()->execute(
            'SELECT worker_key, last_run_at, last_status, state, detail, '
            . 'EXTRACT(EPOCH FROM (now() - last_run_at))::int AS age_seconds '
            . 'FROM worker_heartbeats ORDER BY worker_key',
        )->fetchAll('assoc');

        return $rows;
    }
}
