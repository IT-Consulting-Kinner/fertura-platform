<?php
declare(strict_types=1);

namespace App\Service\Health;

use App\Service\License\LicenseService;
use App\Service\Registry\ContractRegistry;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Aggregiert den Systemzustand über alle Core-Subsysteme (Kap. 20.2.1) und die
 * registrierten Modul-Health-Beiträge (Collector, Kap. 20.2.2).
 *
 * Statuswerte je Subsystem: up | degraded | down. Gesamtstatus: down, sobald
 * die Datenbank nicht erreichbar ist (Liveness), sonst degraded bei mindestens
 * einem nicht-„up"-Subsystem, sonst up.
 */
class HealthService
{
    public const HEALTH_COLLECTOR = 'core.collector.health';

    public function __construct(
        private ?SettingsManager $settings = null,
        private ?ContractRegistry $registry = null,
        private ?LicenseService $license = null,
    ) {
        $this->settings ??= new SettingsManager();
        $this->registry ??= new ContractRegistry();
        $this->license ??= new LicenseService();
    }

    /** Minimaler Liveness-Check (Kap. 20.2.1): nur up/down, ohne Detail. */
    public function liveness(): bool
    {
        try {
            ConnectionManager::get('default')->execute('SELECT 1')->fetch();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Voller Subsystem-Status (auth-/token-geschützt, Kap. 20.2.1).
     *
     * @return array{status: string, subsystems: array<string, mixed>}
     */
    public function report(): array
    {
        $subsystems = [
            'database' => $this->checkDatabase(),
            'storage' => $this->checkStorage(),
            'workers' => $this->checkWorkers(),
            'registry' => $this->checkRegistry(),
            'modules' => $this->checkModules(),
            'outbox' => $this->checkOutbox(),
            'licenses' => $this->checkLicenses(),
            'module_contributions' => $this->collectModuleHealth(),
        ];

        $status = 'up';
        if (($subsystems['database']['status'] ?? 'down') === 'down') {
            $status = 'down';
        } else {
            foreach ($subsystems as $s) {
                if (($s['status'] ?? 'up') !== 'up') {
                    $status = 'degraded';
                    break;
                }
            }
        }

        return ['status' => $status, 'subsystems' => $subsystems];
    }

    private function checkDatabase(): array
    {
        try {
            ConnectionManager::get('default')->execute('SELECT 1')->fetch();

            return ['status' => 'up'];
        } catch (Throwable $e) {
            return ['status' => 'down', 'detail' => $e->getMessage()];
        }
    }

    private function checkStorage(): array
    {
        $base = (string)$this->settings->get('core', 'storage.path', '') ?: TMP;
        $file = rtrim($base, '/') . '/.health_' . bin2hex(random_bytes(4));
        try {
            if (@file_put_contents($file, 'ok') === false) {
                return ['status' => 'down', 'detail' => "nicht schreibbar: $base"];
            }
            $read = @file_get_contents($file);
            @unlink($file);
            if ($read !== 'ok') {
                return ['status' => 'down', 'detail' => "Lesetest fehlgeschlagen: $base"];
            }

            return ['status' => 'up', 'detail' => ['path' => $base]];
        } catch (Throwable $e) {
            @unlink($file);

            return ['status' => 'down', 'detail' => $e->getMessage()];
        }
    }

    private function checkWorkers(): array
    {
        $maxAge = (int)$this->settings->get('core', 'health.worker_max_age_seconds', 120);
        $beats = WorkerHeartbeat::all();
        if ($beats === []) {
            // Noch kein Worker-Lauf protokolliert -> als degraded melden (Soll:
            // Worker-Aktualität ist Teil der Aggregation).
            return ['status' => 'degraded', 'detail' => 'kein Worker-Heartbeat vorhanden'];
        }
        $workers = [];
        $status = 'up';
        foreach ($beats as $b) {
            $age = (int)$b['age_seconds'];
            $stale = $age > $maxAge;
            $wStatus = $b['last_status'] === 'error' ? 'down' : ($stale ? 'degraded' : 'up');
            if ($wStatus !== 'up') {
                $status = $wStatus === 'down' ? 'down' : ($status === 'down' ? 'down' : 'degraded');
            }
            $workers[(string)$b['worker_key']] = [
                'age_seconds' => $age,
                'last_status' => $b['last_status'],
                'stale' => $stale,
            ];
        }

        return ['status' => $status, 'detail' => ['max_age_seconds' => $maxAge, 'workers' => $workers]];
    }

    private function checkRegistry(): array
    {
        try {
            $conn = ConnectionManager::get('default');
            $contracts = (int)$conn->execute('SELECT count(*) FROM contracts WHERE active')->fetch()[0];
            $orphanBindings = (int)$conn->execute(
                "SELECT count(*) FROM capability_bindings cb "
                . "LEFT JOIN contracts c ON c.id = cb.contract_id "
                . "WHERE cb.status = 'active' AND (c.id IS NULL OR NOT c.active)",
            )->fetch()[0];

            return [
                'status' => $orphanBindings > 0 ? 'degraded' : 'up',
                'detail' => ['active_contracts' => $contracts, 'orphan_bindings' => $orphanBindings],
            ];
        } catch (Throwable $e) {
            return ['status' => 'down', 'detail' => $e->getMessage()];
        }
    }

    private function checkModules(): array
    {
        try {
            $rows = ConnectionManager::get('default')->execute(
                'SELECT status, count(*) AS n FROM modules GROUP BY status',
            )->fetchAll('assoc');
            $byStatus = [];
            $errors = 0;
            foreach ($rows as $r) {
                $byStatus[(string)$r['status']] = (int)$r['n'];
                if (str_starts_with((string)$r['status'], 'error')) {
                    $errors += (int)$r['n'];
                }
            }

            return [
                'status' => $errors > 0 ? 'degraded' : 'up',
                'detail' => ['by_status' => $byStatus, 'error_modules' => $errors],
            ];
        } catch (Throwable $e) {
            return ['status' => 'down', 'detail' => $e->getMessage()];
        }
    }

    private function checkOutbox(): array
    {
        try {
            $conn = ConnectionManager::get('default');
            $pending = (int)$conn->execute(
                "SELECT count(*) FROM event_outbox WHERE status = 'pending'",
            )->fetch()[0];
            $deadletter = (int)$conn->execute(
                "SELECT count(*) FROM event_outbox WHERE status = 'dead_letter'",
            )->fetch()[0];

            return [
                'status' => $deadletter > 0 ? 'degraded' : 'up',
                'detail' => ['pending' => $pending, 'deadletter' => $deadletter],
            ];
        } catch (Throwable $e) {
            return ['status' => 'down', 'detail' => $e->getMessage()];
        }
    }

    private function checkLicenses(): array
    {
        try {
            $modules = ConnectionManager::get('default')->execute(
                "SELECT module_key FROM modules WHERE requires_license = true AND status = 'active'",
            )->fetchAll('assoc');
            $invalid = [];
            foreach ($modules as $m) {
                $ev = $this->license->evaluate((string)$m['module_key']);
                if (($ev['status'] ?? 'missing') !== 'valid') {
                    $invalid[(string)$m['module_key']] = $ev['status'] ?? 'missing';
                }
            }

            return [
                'status' => $invalid === [] ? 'up' : 'degraded',
                'detail' => ['invalid' => $invalid],
            ];
        } catch (Throwable $e) {
            return ['status' => 'down', 'detail' => $e->getMessage()];
        }
    }

    /**
     * Aggregiert die Modul-Health-Beiträge (Collector, Kap. 20.2.2). Ohne
     * registrierte Beiträge: Leerergebnis (status up, contributions []).
     */
    private function collectModuleHealth(): array
    {
        $contributions = [];
        $status = 'up';
        try {
            $classes = $this->registry->collectContributionClasses(self::HEALTH_COLLECTOR);
        } catch (Throwable) {
            $classes = [];
        }
        foreach ($classes as $class) {
            try {
                if (!class_exists($class)) {
                    continue;
                }
                $impl = new $class();
                if (!$impl instanceof HealthCheckInterface) {
                    continue;
                }
                $result = $impl->check();
                $contributions[] = $result;
                if (($result['status'] ?? 'up') !== 'up') {
                    $status = 'degraded';
                }
            } catch (Throwable $e) {
                $contributions[] = ['component' => $class, 'status' => 'down', 'detail' => $e->getMessage()];
                $status = 'degraded';
            }
        }

        return ['status' => $status, 'detail' => ['contributions' => $contributions]];
    }
}
