<?php
declare(strict_types=1);

namespace App\Service\Health;

use App\Application;
use App\Service\I18n\LanguagePackStore;
use App\Service\I18n\LocaleResolver;
use App\Service\License\LicenseService;
use App\Service\Marketplace\MarketplaceClient;
use App\Service\Module\ContributionRuntime;
use App\Service\Registry\ContractRegistry;
use App\Service\Settings\SettingsManager;
use App\Service\System\FeatureFlags;
use Cake\Datasource\ConnectionManager;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Aggregates the system state across all core subsystems (ch. 20.2.1) and the
 * registered module health contributions (collector, ch. 20.2.2).
 *
 * Status values per subsystem: up | degraded | down. Overall status: down as
 * soon as the database is unreachable (liveness), otherwise degraded if at
 * least one subsystem is not "up", otherwise up.
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

    /** Minimal liveness check (ch. 20.2.1): only up/down, no detail. */
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
     * Readiness (program tier-3, P15): should this instance receive traffic?
     *
     * For rolling/blue-green deployments: a load balancer/orchestrator only
     * routes to **ready** instances. During an update, maintenance mode
     * (ch. 28) flips the instance to **not ready** (503), so it can be
     * throttled/drained without returning 503 responses to real users.
     *
     * @return array{ready: bool, checks: array<string, bool>}
     */
    public function readiness(): array
    {
        $db = $this->liveness();
        $maintenance = false;
        try {
            $maintenance = (bool)(new SettingsManager())->get('core', 'maintenance_mode', false);
        } catch (Throwable) {
            // Settings not readable -> treat as if not in maintenance mode.
        }

        return ['ready' => $db && !$maintenance, 'checks' => ['database' => $db, 'not_maintenance' => !$maintenance]];
    }

    /**
     * Full subsystem status (auth-/token-protected, ch. 20.2.1).
     *
     * @return array{status: string, subsystems: array<string, mixed>, features: array<string, bool>}
     */
    public function report(): array
    {
        $features = FeatureFlags::all();
        $subsystems = [
            'database' => $this->checkDatabase(),
            'storage' => $this->checkStorage(),
            'workers' => $this->checkWorkers(),
            'registry' => $this->checkRegistry(),
            'modules' => $this->checkModules(),
            'outbox' => $this->checkOutbox(),
            'licenses' => $this->checkLicenses(),
            // Don't probe disabled optional subsystems (no network, no false
            // "degraded") – just report them as disabled.
            'marketplace' => $features['marketplace'] ? $this->checkMarketplace() : ['status' => 'up', 'detail' => 'deaktiviert'],
            'localization' => $this->checkLocalization(),
            'backup' => $this->checkBackup(),
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

        return ['status' => $status, 'subsystems' => $subsystems, 'features' => $features];
    }

    private function checkDatabase(): array
    {
        try {
            $row = ConnectionManager::get('default')->execute(
                'SELECT current_user, (SELECT rolbypassrls FROM pg_roles WHERE rolname = current_user) AS bypass_rls',
            )->fetch('assoc');

            return [
                'status' => 'up',
                // Operational signal (E26): shows that the request path runs
                // as the NOBYPASSRLS app role (RLS is in effect).
                'detail' => [
                    'role' => $row['current_user'] ?? null,
                    'bypass_rls' => $row['bypass_rls'] ?? null,
                ],
            ];
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
            // No worker run logged yet -> report as degraded (by design:
            // worker freshness is part of the aggregation).
            return ['status' => 'degraded', 'detail' => 'kein Worker-Heartbeat vorhanden'];
        }
        $workers = [];
        $status = 'up';
        foreach ($beats as $b) {
            $age = (int)$b['age_seconds'];
            $detail = is_string($b['detail'] ?? null) ? (json_decode((string)$b['detail'], true) ?: []) : (array)($b['detail'] ?? []);
            $interval = (int)($detail['interval_seconds'] ?? 0);
            // Overdue = older than 2x the worker's expected interval
            // (ch. 20.3); without a known interval the global max-age
            // threshold applies.
            $threshold = $interval > 0 ? 2 * $interval : $maxAge;
            $stale = $age > $threshold;
            $wStatus = $b['last_status'] === 'error' ? 'down' : ($stale ? 'degraded' : 'up');
            if ($wStatus !== 'up') {
                $status = $wStatus === 'down' ? 'down' : ($status === 'down' ? 'down' : 'degraded');
            }
            // Quiesce handshake (Phase 2): a worker paused for maintenance keeps
            // refreshing its heartbeat, so a healthy paused worker stays fresh
            // (up) while a crash still surfaces via staleness — no special-casing
            // of the up/degraded decision needed, only visibility of the state.
            $workerState = (string)($b['state'] ?? 'running');
            $workers[(string)$b['worker_key']] = [
                'age_seconds' => $age,
                'last_status' => $b['last_status'],
                'state' => $workerState,
                'paused' => $workerState === 'paused',
                'interval_seconds' => $interval ?: null,
                'overdue_threshold_seconds' => $threshold,
                'last_duration_ms' => isset($detail['duration_ms']) ? (int)$detail['duration_ms'] : null,
                'stale' => $stale,
                'overdue' => $interval > 0 && $age > 2 * $interval,
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
                'SELECT count(*) FROM capability_bindings cb '
                . 'LEFT JOIN contracts c ON c.id = cb.contract_id '
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
            $conn = ConnectionManager::get('default');
            $rows = $conn->execute('SELECT status, count(*) AS n FROM modules GROUP BY status')->fetchAll('assoc');
            $byStatus = [];
            $errors = 0;
            foreach ($rows as $r) {
                $byStatus[(string)$r['status']] = (int)$r['n'];
                if (str_starts_with((string)$r['status'], 'error')) {
                    $errors += (int)$r['n'];
                }
            }
            // Modules whose signature was revoked after the fact (ch. 24.9.2).
            $revoked = (int)$conn->execute(
                "SELECT count(*) FROM modules WHERE signature_status = 'revoked'",
            )->fetch()[0];

            return [
                'status' => $errors > 0 || $revoked > 0 ? 'degraded' : 'up',
                'detail' => ['by_status' => $byStatus, 'error_modules' => $errors, 'revoked_signature' => $revoked],
            ];
        } catch (Throwable $e) {
            return ['status' => 'down', 'detail' => $e->getMessage()];
        }
    }

    private function checkMarketplace(): array
    {
        try {
            $baseUrl = (string)$this->settings->get('core', 'marketplace.base_url', '');
            if ($baseUrl === '') {
                return ['status' => 'up', 'detail' => 'nicht konfiguriert'];
            }
            $crl = (new MarketplaceClient())->crlState();

            return [
                'status' => $crl['stale'] ? 'degraded' : 'up',
                'detail' => [
                    'crl_last_fetch_at' => $crl['last_fetch_at'],
                    'crl_age_days' => $crl['age_days'],
                    'crl_max_age_days' => $crl['max_age_days'],
                    'crl_stale' => $crl['stale'],
                ],
            ];
        } catch (Throwable $e) {
            return ['status' => 'degraded', 'detail' => $e->getMessage()];
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
     * Localization (i18n-8): missing English base of active components,
     * version errors (major mismatch of the packs in use) and orphaned/in-flight
     * `.tmp` files in the store. Read-only (does not heal; use
     * `bin/cake lang recover` for that).
     */
    private function checkLocalization(): array
    {
        try {
            $conn = ConnectionManager::get('default');
            $resolver = new LocaleResolver();
            $coreVersion = Application::CORE_VERSION;

            $rows = $conn->execute(
                'SELECT lp.component_key, lp.locale, lp.version, '
                . 'COALESCE(m.version, :core) AS active_version, '
                . "COALESCE(m.status, 'inactive') AS comp_status "
                . 'FROM language_packs lp '
                . 'LEFT JOIN modules m ON m.module_key = lp.component_key',
                ['core' => $coreVersion],
            )->fetchAll('assoc');

            $versionErrors = [];
            $activeComponents = [];
            foreach ($rows as $r) {
                $active = (string)$r['component_key'] === 'core' || (string)$r['comp_status'] === 'active';
                if (!$active) {
                    continue;
                }
                $key = (string)$r['component_key'];
                $activeComponents[$key] = (string)$r['active_version'];
                $packMajor = explode('.', (string)$r['version'])[0];
                $activeMajor = explode('.', (string)$r['active_version'])[0];
                if ($packMajor !== $activeMajor) {
                    $versionErrors[] = $key . '/' . $r['locale'] . ' v' . $r['version'] . ' (aktiv v' . $r['active_version'] . ')';
                }
            }

            // Missing English base: active non-core component without a usable
            // en_US (the core always ships English via resources/locales).
            $missingBase = [];
            foreach ($activeComponents as $key => $ver) {
                if ($key === 'core') {
                    continue;
                }
                if ($resolver->resolveVersion($key, $ver, 'en_US') === null) {
                    $missingBase[] = $key . ' v' . $ver;
                }
            }

            $tmp = $this->countStoreTmp();

            $degraded = $missingBase !== [] || $versionErrors !== [] || $tmp > 0;

            return [
                'status' => $degraded ? 'degraded' : 'up',
                'detail' => [
                    'active_components' => count($activeComponents),
                    'missing_english_base' => $missingBase,
                    'version_errors' => $versionErrors,
                    'stray_tmp_files' => $tmp,
                ],
            ];
        } catch (Throwable $e) {
            return ['status' => 'down', 'detail' => $e->getMessage()];
        }
    }

    /**
     * Data backup (ch. 20.1.2): with the scheduler active, degraded if the most
     * recent backup is missing, failed, or older than 2x the interval; reports
     * age/count/encryption.
     */
    private function checkBackup(): array
    {
        try {
            $conn = ConnectionManager::get('default');
            $settings = $this->settings;
            $enabled = (bool)$settings->get('core', 'backup.schedule.enabled', false);
            $interval = (int)$settings->get('core', 'backup.schedule.interval_hours', 24) * 3600;
            $encrypted = trim((string)$settings->get('core', 'backup.password', '')) !== '';

            $row = $conn->execute(
                "SELECT count(*) FILTER (WHERE status='complete') AS ok, "
                . "count(*) FILTER (WHERE status='failed') AS failed, "
                . "EXTRACT(EPOCH FROM (now() - max(created_at) FILTER (WHERE status='complete')))::int AS age "
                . 'FROM backups',
            )->fetch('assoc');
            $okCount = (int)$row['ok'];
            $age = $row['age'] !== null ? (int)$row['age'] : null;

            $status = 'up';
            $reason = null;
            if ($enabled) {
                if ($okCount === 0 || $age === null) {
                    $status = 'degraded';
                    $reason = 'kein erfolgreiches Backup';
                } elseif ($age > 2 * $interval) {
                    $status = 'degraded';
                    $reason = 'jüngstes Backup überfällig';
                }
            }

            return [
                'status' => $status,
                'detail' => [
                    'scheduler_enabled' => $enabled,
                    'encrypted' => $encrypted,
                    'complete_backups' => $okCount,
                    'failed_backups' => (int)$row['failed'],
                    'newest_age_seconds' => $age,
                    'reason' => $reason,
                ],
            ];
        } catch (Throwable $e) {
            return ['status' => 'degraded', 'detail' => $e->getMessage()];
        }
    }

    private function countStoreTmp(): int
    {
        $base = (new LanguagePackStore())->base();
        if (!is_dir($base)) {
            return 0;
        }
        $n = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $f) {
            if (substr((string)$f, -7) === '.po.tmp') {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Aggregates the module health contributions (collector, ch. 20.2.2). With
     * no registered contributions: empty result (status up, contributions []).
     */
    private function collectModuleHealth(): array
    {
        $contributions = [];
        $status = 'up';
        try {
            $contribs = (new ContributionRuntime($this->registry))->collectors(self::HEALTH_COLLECTOR);
        } catch (Throwable) {
            $contribs = [];
        }
        foreach ($contribs as $contrib) {
            try {
                // In-process locally, out_of_process via the isolated host (RPC).
                $result = (array)(new ContributionRuntime($this->registry))->call($contrib, 'check', []);
                $contributions[] = $result;
                if (($result['status'] ?? 'up') !== 'up') {
                    $status = 'degraded';
                }
            } catch (Throwable $e) {
                $contributions[] = ['component' => $contrib['class'], 'status' => 'down', 'detail' => $e->getMessage()];
                $status = 'degraded';
            }
        }

        return ['status' => $status, 'detail' => ['contributions' => $contributions]];
    }
}
