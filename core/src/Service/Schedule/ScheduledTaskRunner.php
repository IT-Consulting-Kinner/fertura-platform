<?php
declare(strict_types=1);

namespace App\Service\Schedule;

use App\Service\Health\WorkerHeartbeat;
use App\Service\Registry\ContractRegistry;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Throwable;

/**
 * Tickt periodische Modul-Aufgaben (Andock-Punkt für Module, Kap. 20.3/20.4).
 *
 * Wird vom Core-Worker je Zyklus aufgerufen: sammelt die für den Collector
 * `core.collector.scheduled` registrierten Implementierungen, prüft je Aufgabe
 * anhand des letzten Laufs (Heartbeat `sched:<key>`), ob das Intervall abgelaufen
 * ist, und führt sie fehlerisoliert aus. Die Aufgaben erscheinen dadurch auch in
 * der Worker-Health-Übersicht.
 *
 * **Mehrinstanz-sicher:** Jede Aufgabe wird über einen PostgreSQL-Advisory-Lock
 * je Aufgaben-Schlüssel serialisiert — laufen mehrere Worker-Instanzen, führt
 * trotzdem nur **eine** dieselbe Aufgabe gleichzeitig aus (kein Doppellauf, z. B.
 * doppelte geplante Backups). Der Fälligkeits-Check liegt **innerhalb** des Locks.
 */
class ScheduledTaskRunner
{
    public const COLLECTOR = 'core.collector.scheduled';

    /** @var list<class-string> Vom Core mitgelieferte periodische Aufgaben. */
    private const CORE_TASKS = [
        \App\Service\Backup\BackupScheduledTask::class,
    ];

    public function __construct(private ?ContractRegistry $registry = null)
    {
        $this->registry ??= new ContractRegistry();
    }

    /**
     * Tickt alle registrierten Aufgaben. Gibt die Schlüssel der gelaufenen zurück.
     *
     * @return list<string>
     */
    public function tick(): array
    {
        try {
            $classes = $this->registry->collectContributionClasses(self::COLLECTOR);
        } catch (Throwable) {
            $classes = [];
        }

        // Core-eigene Aufgaben (z. B. automatisches Backup) immer mitführen.
        return $this->tickClasses([...self::CORE_TASKS, ...$classes]);
    }

    /**
     * @param list<string> $classes
     * @return list<string>
     */
    public function tickClasses(array $classes): array
    {
        $ran = [];
        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class)) {
                continue;
            }
            $task = new $class();
            if (!$task instanceof ScheduledTaskInterface) {
                continue;
            }
            $hbKey = 'sched:' . $task->key();

            // Mehrinstanz-Sicherheit: nur ein Worker bearbeitet eine Aufgabe
            // gleichzeitig. Bekommt ein anderer Worker den Lock nicht, überspringt
            // er die Aufgabe. Fälligkeits-Check liegt im Lock (kein Race).
            $lockKey = $this->lockKey($hbKey);
            if (!$this->tryLock($lockKey)) {
                continue;
            }
            try {
                $age = $this->ageSeconds($hbKey);
                if ($age !== null && $age < $task->intervalSeconds()) {
                    continue; // noch nicht fällig
                }
                try {
                    $task->run();
                    WorkerHeartbeat::beat($hbKey, 'ok', ['interval_seconds' => $task->intervalSeconds()]);
                    $ran[] = $task->key();
                } catch (Throwable $e) {
                    WorkerHeartbeat::beat($hbKey, 'error', ['interval_seconds' => $task->intervalSeconds(), 'error' => $e->getMessage()]);
                    Log::error('Scheduled task failed: ' . $task->key(), ['module' => $task->key(), 'exception' => $e->getMessage()]);
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
