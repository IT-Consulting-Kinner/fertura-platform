<?php
declare(strict_types=1);

namespace App\Service\Schedule;

use App\Service\Health\WorkerHeartbeat;
use App\Service\Module\ContributionRuntime;
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
        // Core-eigene Aufgaben (in-process) immer mitführen.
        $tasks = array_map(
            static fn ($c) => ['class' => $c, 'module_key' => 'core', 'isolation' => 'in_process'],
            self::CORE_TASKS,
        );
        // Modul-Aufgaben MIT Isolationsmodus (out_of_process -> über RPC im Host).
        try {
            $tasks = array_merge($tasks, (new ContributionRuntime($this->registry))->collectors(self::COLLECTOR));
        } catch (Throwable) {
            // keine Modul-Aufgaben
        }

        return $this->tickContributions($tasks);
    }

    /**
     * Alt-Pfad: tickt nackte Klassennamen (in-process). Für Tests/Kompatibilität.
     *
     * @param list<string> $classes
     * @return list<string>
     */
    public function tickClasses(array $classes): array
    {
        return $this->tickContributions(array_map(
            static fn ($c) => ['class' => $c, 'module_key' => 'core', 'isolation' => 'in_process'],
            array_values(array_filter($classes, 'is_string')),
        ));
    }

    /**
     * @param list<array{class:string, module_key:string, isolation?:string}> $tasks
     * @return list<string>
     */
    private function tickContributions(array $tasks): array
    {
        $runtime = new ContributionRuntime($this->registry);
        $ran = [];
        foreach ($tasks as $task) {
            // Metadaten (Schlüssel/Intervall) — in-process lokal, out_of_process
            // über RPC im Host (wie run() selbst).
            try {
                $key = (string)$runtime->call($task, 'key', []);
                $interval = (int)$runtime->call($task, 'intervalSeconds', []);
            } catch (Throwable) {
                continue; // ungültige/nicht ladbare Aufgabe
            }
            if ($key === '') {
                continue;
            }
            $hbKey = 'sched:' . $key;

            // Mehrinstanz-Sicherheit: nur ein Worker bearbeitet eine Aufgabe
            // gleichzeitig. Fälligkeits-Check liegt im Lock (kein Race).
            $lockKey = $this->lockKey($hbKey);
            if (!$this->tryLock($lockKey)) {
                continue;
            }
            try {
                $age = $this->ageSeconds($hbKey);
                if ($age !== null && $age < $interval) {
                    continue; // noch nicht fällig
                }
                try {
                    // Systemkontext (kein laufender Benutzer) -> Bypass.
                    $runtime->call($task, 'run', [], ['bypass' => true]);
                    WorkerHeartbeat::beat($hbKey, 'ok', ['interval_seconds' => $interval]);
                    $ran[] = $key;
                } catch (Throwable $e) {
                    WorkerHeartbeat::beat($hbKey, 'error', ['interval_seconds' => $interval, 'error' => $e->getMessage()]);
                    Log::error('Scheduled task failed: ' . $key, ['module' => $key, 'exception' => $e->getMessage()]);
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
