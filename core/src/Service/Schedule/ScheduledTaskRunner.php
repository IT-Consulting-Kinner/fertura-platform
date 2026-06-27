<?php
declare(strict_types=1);

namespace App\Service\Schedule;

use App\Service\Backup\BackupScheduledTask;
use App\Service\Backup\TenantBackupScheduledTask;
use App\Service\Health\WorkerHeartbeat;
use App\Service\Module\ContributionRuntime;
use App\Service\Registry\ContractRegistry;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Throwable;

/**
 * Ticks periodic module tasks (extension point for modules, ch. 20.3/20.4).
 *
 * Called by the core worker each cycle: collects the implementations registered
 * for the collector `core.collector.scheduled`, checks per task — based on the
 * last run (heartbeat `sched:<key>`) — whether the interval has elapsed, and
 * runs them error-isolated. Through this, the tasks also appear in the worker
 * health overview.
 *
 * **Multi-instance safe:** each task is serialized via a PostgreSQL advisory lock
 * keyed by the task key — if multiple worker instances run, still only **one**
 * executes the same task at a time (no double run, e.g. duplicate scheduled
 * backups). The due check sits **inside** the lock.
 */
class ScheduledTaskRunner
{
    public const COLLECTOR = 'core.collector.scheduled';

    /** @var list<class-string> Periodic tasks shipped with the core. */
    private const CORE_TASKS = [
        BackupScheduledTask::class,
        TenantBackupScheduledTask::class,
    ];

    public function __construct(private ?ContractRegistry $registry = null)
    {
        $this->registry ??= new ContractRegistry();
    }

    /**
     * Ticks all registered tasks. Returns the keys of those that ran.
     *
     * @return list<string>
     */
    public function tick(): array
    {
        // Always include the core's own tasks.
        $tasks = array_map(
            static fn($c) => ['class' => $c, 'module_key' => 'core'],
            self::CORE_TASKS,
        );
        // Module-contributed scheduled tasks (run in-process).
        try {
            $tasks = array_merge($tasks, (new ContributionRuntime($this->registry))->collectors(self::COLLECTOR));
        } catch (Throwable) {
            // no module tasks
        }

        return $this->tickContributions($tasks);
    }

    /**
     * Legacy path: ticks bare class names (in-process). For tests/compatibility.
     *
     * @param list<string> $classes
     * @return list<string>
     */
    public function tickClasses(array $classes): array
    {
        return $this->tickContributions(array_map(
            static fn($c) => ['class' => $c, 'module_key' => 'core'],
            array_values(array_filter($classes, 'is_string')),
        ));
    }

    /**
     * @param list<array{class:string, module_key:string}> $tasks
     * @return list<string>
     */
    private function tickContributions(array $tasks): array
    {
        $runtime = new ContributionRuntime($this->registry);
        $ran = [];
        foreach ($tasks as $task) {
            // Metadata (key/interval) — local for in-process, via RPC in the host
            // for out_of_process (like run() itself).
            try {
                $key = (string)$runtime->call($task, 'key', []);
                $interval = (int)$runtime->call($task, 'intervalSeconds', []);
            } catch (Throwable) {
                continue; // invalid/unloadable task
            }
            if ($key === '') {
                continue;
            }
            $hbKey = 'sched:' . $key;

            // Multi-instance safety: only one worker processes a task at a time.
            // The due check sits inside the lock (no race).
            $lockKey = $this->lockKey($hbKey);
            if (!$this->tryLock($lockKey)) {
                continue;
            }
            try {
                $age = $this->ageSeconds($hbKey);
                if ($age !== null && $age < $interval) {
                    continue; // not yet due
                }
                try {
                    // System context (no active user) -> bypass.
                    $runtime->call($task, 'run', [], ['bypass' => true]);
                    WorkerHeartbeat::beat($hbKey, 'ok', ['interval_seconds' => $interval]);
                    $ran[] = $key;
                } catch (Throwable $e) {
                    WorkerHeartbeat::beat($hbKey, 'error', ['interval_seconds' => $interval, 'error' => $e->getMessage()]);
                    Log::error('Scheduled task failed: ' . $key, ['task' => $key, 'exception' => $e->getMessage()]);
                }
            } finally {
                $this->unlock($lockKey);
            }
        }

        return $ran;
    }

    private function ageSeconds(string $hbKey): ?int
    {
        $row = ConnectionManager::get('default')->execute(
            'SELECT EXTRACT(EPOCH FROM (now() - last_run_at))::int AS age FROM worker_heartbeats WHERE worker_key = :k',
            ['k' => $hbKey],
        )->fetch('assoc');

        return $row === false ? null : (int)$row['age'];
    }

    private function lockKey(string $s): int
    {
        $row = ConnectionManager::get('default')->execute('SELECT hashtext(:p)::bigint AS k', ['p' => $s])->fetch('assoc');

        return (int)$row['k'];
    }

    private function tryLock(int $key): bool
    {
        $row = ConnectionManager::get('default')->execute('SELECT pg_try_advisory_lock(:k) AS ok', ['k' => $key])->fetch('assoc');

        return $row['ok'] === true || $row['ok'] === 't';
    }

    private function unlock(int $key): void
    {
        ConnectionManager::get('default')->execute('SELECT pg_advisory_unlock(:k)', ['k' => $key]);
    }
}
