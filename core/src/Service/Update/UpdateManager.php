<?php
declare(strict_types=1);

namespace App\Service\Update;

use App\Application;
use App\Audit\AuditLogger;
use App\Model\Entity\ContractRegistration;
use App\Service\Module\LifecycleException;
use App\Service\Module\ModuleManifest;
use App\Service\Module\ModuleMigrationRunner;
use App\Service\Registry\ContractRegistry;
use App\Service\Registry\SemVer;
use App\Service\Registry\VersionConstraint;
use App\Service\Security\PackageVerificationException;
use App\Service\Security\PackageVerifier;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;
use Migrations\Migrations;
use Throwable;

/**
 * Update-Manager (Step 8, Kap. 28.8/28.9/28.14).
 *
 * Modul- und Core-Updates mit Signaturprüfung, Kompatibilitätsprüfung,
 * verpflichtendem Wiederherstellungspunkt bei Migrationen, Migration,
 * Registry-Revalidierung, Audit/Historie und Rollback-Kaskade.
 */
class UpdateManager
{
    private const LIFECYCLE_LOCK = 778899001;

    private ContractRegistry $registry;
    private AuditLogger $audit;
    private ModuleMigrationRunner $migrations;
    private PackageVerifier $verifier;
    private SettingsManager $settings;
    private RecoveryPoint $recovery;
    private string $coreVersion;

    public function __construct(
        ?ContractRegistry $registry = null,
        ?AuditLogger $audit = null,
        ?ModuleMigrationRunner $migrations = null,
        ?PackageVerifier $verifier = null,
        ?SettingsManager $settings = null,
        ?RecoveryPoint $recovery = null,
        ?string $coreVersion = null,
    ) {
        $this->registry = $registry ?? new ContractRegistry();
        $this->audit = $audit ?? new AuditLogger();
        $this->migrations = $migrations ?? new ModuleMigrationRunner();
        $this->verifier = $verifier ?? new PackageVerifier();
        $this->settings = $settings ?? new SettingsManager();
        $this->recovery = $recovery ?? new RecoveryPoint();
        $this->coreVersion = $coreVersion ?? Application::CORE_VERSION;
    }

    private function conn()
    {
        return \App\Infrastructure\Db::privileged();
    }

    // ---- Modul-Update --------------------------------------------------------

    /** @return array<string, mixed> */
    /**
     * Migrations-/Kompatibilitätsvorschau für ein Modul-Update OHNE Ausführung
     * (Kap. 24.13 Schritt 8). Liefert Versionsdelta, ausstehende Migrationen und
     * blockierende Fehler (Signatur/Manifest/Downgrade).
     *
     * @return array<string, mixed>
     */
    public function previewModule(string $key, string $newSourcePath): array
    {
        $newSourcePath = rtrim($newSourcePath, '/');
        $mod = $this->findModuleOrFail($key);
        $errors = [];

        $manifestFile = $newSourcePath . '/manifest.json';
        if (!is_file($manifestFile)) {
            return ['module_key' => $key, 'ok' => false, 'errors' => ['manifest.json nicht gefunden: ' . $newSourcePath]];
        }
        $manifest = ModuleManifest::fromJson((string)file_get_contents($manifestFile));
        if ($manifest->key() !== $key) {
            $errors[] = "Paket gehört nicht zu Modul $key (id={$manifest->key()}).";
        }
        try {
            $this->verify($newSourcePath, $manifest);
        } catch (Throwable $e) {
            $errors[] = 'Signatur: ' . $e->getMessage();
        }
        $errors = array_merge($errors, $manifest->validate($this->coreVersion));

        $oldVersion = (string)$mod['version'];
        $newVersion = $manifest->version();
        $isDowngrade = false;
        try {
            $isDowngrade = SemVer::parse($newVersion)->compare(SemVer::parse($oldVersion)) < 0;
        } catch (Throwable $e) {
            $errors[] = 'Version: ' . $e->getMessage();
        }
        if ($isDowngrade) {
            $errors[] = "Zielversion $newVersion ist älter als $oldVersion.";
        }

        $pending = $this->migrations->listPending((string)$mod['id'], $newSourcePath . '/migrations');

        return [
            'module_key' => $key,
            'current_version' => $oldVersion,
            'new_version' => $newVersion,
            'is_downgrade' => $isDowngrade,
            'is_security' => $manifest->isSecurityUpdate(),
            'pending_migrations' => $pending,
            'errors' => $errors,
            'ok' => $errors === [],
        ];
    }

    /**
     * Vorschau für ein Core-Update: ausstehende Core-Migrationen + inkompatible
     * Module (ohne Ausführung).
     *
     * @return array<string, mixed>
     */
    public function previewCore(string $targetVersion): array
    {
        $errors = [];
        try {
            SemVer::parse($targetVersion);
        } catch (Throwable $e) {
            $errors[] = 'Zielversion: ' . $e->getMessage();
        }

        $incompatible = [];
        foreach ($this->conn()->execute('SELECT module_key, core_compatibility FROM modules')->fetchAll('assoc') as $m) {
            $cc = (string)$m['core_compatibility'];
            if ($cc === '') {
                continue;
            }
            try {
                if (!VersionConstraint::parse($cc)->isSatisfiedBy(SemVer::parse($targetVersion))) {
                    $incompatible[] = "{$m['module_key']} ($cc)";
                }
            } catch (Throwable) {
                $incompatible[] = "{$m['module_key']} (ungültiges core_compatibility)";
            }
        }

        $pending = [];
        try {
            $status = (new Migrations(['connection' => \App\Infrastructure\Db::privilegedName()]))->status();
            foreach ($status as $row) {
                if (($row['status'] ?? '') === 'down') {
                    $pending[] = trim(($row['id'] ?? '') . ' ' . ($row['name'] ?? ''));
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'Migrationsstatus: ' . $e->getMessage();
        }

        return [
            'current_version' => $this->coreVersion,
            'target_version' => $targetVersion,
            'incompatible_modules' => $incompatible,
            'pending_migrations' => $pending,
            'errors' => $errors,
            'ok' => $errors === [],
        ];
    }

    public function updateModule(string $key, string $newSourcePath): array
    {
        return $this->withLock(function () use ($key, $newSourcePath): array {
            $newSourcePath = rtrim($newSourcePath, '/');
            $mod = $this->findModuleOrFail($key);
            $manifest = ModuleManifest::fromJson((string)file_get_contents($newSourcePath . '/manifest.json'));
            if ($manifest->key() !== $key) {
                throw new LifecycleException("Paket gehört nicht zu Modul $key (id={$manifest->key()}).");
            }

            $this->verify($newSourcePath, $manifest);
            $errors = $manifest->validate($this->coreVersion);
            if ($errors !== []) {
                throw new LifecycleException('Manifest ungültig: ' . implode(' ', $errors));
            }

            $oldVersion = (string)$mod['version'];
            $newVersion = $manifest->version();
            if (SemVer::parse($newVersion)->compare(SemVer::parse($oldVersion)) < 0) {
                throw new LifecycleException("Zielversion $newVersion ist älter als $oldVersion.");
            }

            $moduleId = (string)$mod['id'];
            $schema = 'mod_' . $key;
            $targetPath = (string)$mod['source_path'];
            $wasActive = $mod['status'] === 'active';

            $hasMigrations = $this->hasPendingMigrations($moduleId, $newSourcePath . '/migrations');
            $recoveryPath = null;
            if ($hasMigrations) {
                $recoveryPath = $this->recovery->create("update_module_$key");
            }
            // Bereits angewendete Migrationen merken: bei einem Fehlschlag dürfen
            // NUR die in DIESEM Update neu angewendeten zurückgerollt werden — die
            // bereits beim Install angewendeten bleiben (sonst Datenverlust).
            $preApplied = $this->appliedMigrations($moduleId, $newSourcePath . '/migrations');

            $backupPath = $targetPath . '.bak';
            $this->removeDir($backupPath);

            try {
                if ($wasActive) {
                    $this->deactivateRegistrations($key);
                }
                // Code austauschen (alten Stand sichern für Rollback).
                if (is_dir($targetPath)) {
                    rename($targetPath, $backupPath);
                }
                $this->copyDir($newSourcePath, $targetPath);

                $this->conn()->execute(
                    'UPDATE modules SET version = :v, manifest = CAST(:m AS jsonb) WHERE module_key = :k',
                    ['v' => $newVersion, 'm' => json_encode($manifest->data), 'k' => $key],
                );

                $this->migrations->runUp($moduleId, $schema, $targetPath . '/migrations');

                // Contracts neu aufbauen (vom Modul definierte).
                $this->conn()->execute('DELETE FROM contracts WHERE owner_module_key = :k', ['k' => $key]);
                foreach ($manifest->contractsProvided() as $c) {
                    $this->registry->registerContract($key, (string)$c['name'], (string)$c['type'], (string)$c['version']);
                }
                if ($wasActive) {
                    $this->reactivateRegistrations($key, $manifest, $newVersion);
                    $this->conn()->execute("UPDATE modules SET status = 'active' WHERE module_key = :k", ['k' => $key]);
                }

                $this->removeDir($backupPath);
                $this->recordHistory('module', $key, $oldVersion, $newVersion, 'success', null, $recoveryPath, $manifest->isSecurityUpdate(), $manifest->severity());
                $this->audit->log('module.update', 'module', $key, [
                    'oldValue' => ['version' => $oldVersion],
                    'newValue' => ['version' => $newVersion],
                    'moduleKey' => $key,
                    'moduleName' => $manifest->name(),
                    'moduleVersion' => $newVersion,
                ]);

                return $this->findModuleOrFail($key);
            } catch (Throwable $e) {
                // Rollback-Kaskade (Kap. 28.14.2): down-Migrationen -> Dateien ->
                // Stammdaten/Registry. Der pg_dump-Recovery-Point bleibt als
                // manuelle letzte Zuflucht erhalten (kein gefährlicher Auto-Restore).
                $result = 'failed';
                try {
                    $files = glob($newSourcePath . '/migrations/*.sql') ?: [];
                    rsort($files);
                    foreach ($files as $f) {
                        $name = basename($f);
                        // Nur in diesem Update neu angewendete Migrationen zurückrollen.
                        if ($this->migrations->isApplied($moduleId, $name) && !in_array($name, $preApplied, true)) {
                            $this->migrations->runDown($moduleId, $schema, $newSourcePath . '/migrations', $name);
                        }
                    }

                    $this->removeDir($targetPath);
                    if (is_dir($backupPath)) {
                        rename($backupPath, $targetPath);
                    }

                    $oldManifest = new ModuleManifest(json_decode((string)$mod['manifest'], true) ?: []);
                    $this->conn()->execute(
                        'UPDATE modules SET version = :v, manifest = CAST(:m AS jsonb), status = :s WHERE module_key = :k',
                        ['v' => $oldVersion, 'm' => json_encode($oldManifest->data), 's' => $mod['status'], 'k' => $key],
                    );
                    $this->conn()->execute('DELETE FROM contracts WHERE owner_module_key = :k', ['k' => $key]);
                    $this->conn()->execute('DELETE FROM contract_registrations WHERE module_key = :k', ['k' => $key]);
                    $this->conn()->execute('DELETE FROM capability_bindings WHERE module_key = :k', ['k' => $key]);
                    foreach ($oldManifest->contractsProvided() as $c) {
                        $this->registry->registerContract($key, (string)$c['name'], (string)$c['type'], (string)$c['version']);
                    }
                    if ($wasActive) {
                        $this->reactivateRegistrations($key, $oldManifest, $oldVersion);
                    }
                    $result = 'rolled_back';
                } catch (Throwable) {
                    $result = 'failed';
                }
                $this->recordHistory('module', $key, $oldVersion, $newVersion, $result, $e->getMessage(), $recoveryPath, $manifest->isSecurityUpdate(), $manifest->severity());
                $this->audit->log('module.update_failed', 'module', $key, [
                    'newValue' => ['error' => $e->getMessage(), 'result' => $result],
                    'moduleKey' => $key,
                ]);
                throw new LifecycleException("Modul-Update fehlgeschlagen ($result): " . $e->getMessage(), 0, $e);
            }
        });
    }

    // ---- Core-Update ---------------------------------------------------------

    /**
     * Orchestriert ein Core-Update: Kompatibilitätsprüfung gegen installierte
     * Module, Wiederherstellungspunkt, Core-Migrationen, Historie/Audit.
     *
     * (Der Austausch des Core-Codes selbst wird umgebungsseitig durchgeführt;
     * diese Methode führt die migrations-/sicherheitsrelevante Orchestrierung aus.)
     *
     * @return array<string, mixed>
     */
    public function updateCore(string $targetVersion, bool $force = false): array
    {
        return $this->withLock(function () use ($targetVersion, $force): array {
            SemVer::parse($targetVersion);
            $oldVersion = $this->coreVersion;

            // Kompatibilität: jedes installierte Modul muss targetVersion zulassen.
            $incompatible = [];
            $modules = $this->conn()->execute(
                'SELECT module_key, core_compatibility FROM modules',
            )->fetchAll('assoc');
            foreach ($modules as $m) {
                $cc = (string)$m['core_compatibility'];
                if ($cc === '') {
                    continue;
                }
                try {
                    if (!VersionConstraint::parse($cc)->isSatisfiedBy(SemVer::parse($targetVersion))) {
                        $incompatible[] = "{$m['module_key']} ($cc)";
                    }
                } catch (Throwable) {
                    $incompatible[] = "{$m['module_key']} (ungültiges core_compatibility)";
                }
            }
            if ($incompatible !== [] && !$force) {
                throw new LifecycleException(
                    'Core-Update blockiert, inkompatible Module: ' . implode(', ', $incompatible)
                );
            }

            // Wiederherstellungspunkt (Core-Update kann Migrationen enthalten).
            $recoveryPath = $this->recovery->create('update_core');

            try {
                // Ausstehende Core-Migrationen ausführen (privilegierte Connection
                // wegen DDL; Default läuft als NOBYPASSRLS-Rolle, E26).
                $migrations = new Migrations(['connection' => \App\Infrastructure\Db::privilegedName()]);
                $migrations->migrate();

                $this->recordHistory('core', 'core', $oldVersion, $targetVersion, 'success', null, $recoveryPath);
                $this->audit->log('core.update', 'core', 'core', [
                    'oldValue' => ['version' => $oldVersion],
                    'newValue' => ['version' => $targetVersion],
                ]);

                return ['old_version' => $oldVersion, 'new_version' => $targetVersion, 'recovery_point' => $recoveryPath];
            } catch (Throwable $e) {
                // Core-Migrationen sind transaktional (cakephp/migrations rollt die
                // fehlgeschlagene Migration atomar zurück). Kein gefährlicher
                // Auto-Restore; der Recovery-Point bleibt für den manuellen Notfall.
                $this->recordHistory('core', 'core', $oldVersion, $targetVersion, 'failed', $e->getMessage(), $recoveryPath);
                $this->audit->log('core.update_failed', 'core', 'core', [
                    'newValue' => ['error' => $e->getMessage(), 'result' => 'failed'],
                ]);
                throw new LifecycleException('Core-Update fehlgeschlagen: ' . $e->getMessage(), 0, $e);
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public function listHistory(): array
    {
        return $this->conn()->execute(
            'SELECT component_type, component_key, old_version, new_version, result, executed_at, is_security, severity '
            . 'FROM update_history ORDER BY executed_at DESC LIMIT 20',
        )->fetchAll('assoc');
    }

    // ---- intern --------------------------------------------------------------

    private function recordHistory(
        string $type,
        string $key,
        ?string $old,
        ?string $new,
        string $result,
        ?string $error,
        ?string $recoveryPath,
        bool $isSecurity = false,
        ?string $severity = null,
    ): void {
        $this->conn()->execute(
            'INSERT INTO update_history (component_type, component_key, old_version, new_version, result, error, recovery_point, is_security, severity) '
            . 'VALUES (:t, :k, :o, :n, :r, :e, :rp, :sec, :sev)',
            ['t' => $type, 'k' => $key, 'o' => $old, 'n' => $new, 'r' => $result, 'e' => $error, 'rp' => $recoveryPath,
                'sec' => $isSecurity ? 'true' : 'false', 'sev' => $severity],
        );
    }

    private function verify(string $sourcePath, ModuleManifest $manifest): void
    {
        if (!(bool)$this->settings->get('core', 'require_module_signature', true)) {
            return;
        }
        try {
            $this->verifier->verify($sourcePath, $manifest->publisher());
        } catch (PackageVerificationException $e) {
            throw new LifecycleException('Signaturprüfung fehlgeschlagen: ' . $e->getMessage());
        }
    }

    /**
     * Basenamen der im Verzeichnis enthaltenen, **bereits angewendeten**
     * Migrationen (für die korrekte Eingrenzung des Update-Rückbaus).
     *
     * @return list<string>
     */
    private function appliedMigrations(string $moduleId, string $dir): array
    {
        $out = [];
        foreach (glob($dir . '/*.sql') ?: [] as $f) {
            $name = basename($f);
            if ($this->migrations->isApplied($moduleId, $name)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    private function hasPendingMigrations(string $moduleId, string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        foreach (glob($dir . '/*.sql') ?: [] as $file) {
            $done = $this->conn()->execute(
                'SELECT 1 FROM module_migrations_log WHERE module_id = :m AND migration_name = :n',
                ['m' => $moduleId, 'n' => basename($file)],
            )->fetch();
            if (!$done) {
                return true;
            }
        }

        return false;
    }

    private function deactivateRegistrations(string $key): void
    {
        $rows = $this->conn()->execute(
            'SELECT id FROM contract_registrations WHERE module_key = :k AND active',
            ['k' => $key],
        )->fetchAll('assoc');
        foreach ($rows as $row) {
            $this->registry->deactivateRegistration((string)$row['id']);
        }
    }

    private function reactivateRegistrations(string $key, ModuleManifest $manifest, string $moduleVersion): void
    {
        foreach ($manifest->resolversRegistered() as $r) {
            $this->registry->register($key, (string)$r['contract'], ContractRegistration::TYPE_PROVIDER, [
                'implementationClass' => $r['class'] ?? null, 'requiredVersion' => $r['version'] ?? null, 'moduleVersion' => $moduleVersion,
            ]);
        }
        foreach ($manifest->collectorsRegistered() as $c) {
            $this->registry->register($key, (string)$c['contract'], ContractRegistration::TYPE_COLLECTOR, [
                'implementationClass' => $c['class'] ?? null, 'requiredVersion' => $c['version'] ?? null,
                'priority' => (int)($c['priority'] ?? 0), 'moduleVersion' => $moduleVersion,
            ]);
        }
        foreach ($manifest->eventsRegistered() as $e) {
            $this->registry->register($key, (string)$e['contract'], ContractRegistration::TYPE_LISTENER, [
                'implementationClass' => $e['class'] ?? null, 'requiredVersion' => $e['version'] ?? null, 'moduleVersion' => $moduleVersion,
            ]);
        }
        foreach ($manifest->contractsUsed() as $u) {
            $this->registry->register($key, (string)$u['contract'], ContractRegistration::TYPE_CONSUMER, [
                'requiredVersion' => $u['version'] ?? null, 'moduleVersion' => $moduleVersion,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function findModuleOrFail(string $key): array
    {
        $row = $this->conn()->execute('SELECT * FROM modules WHERE module_key = :k', ['k' => $key])->fetch('assoc');
        if ($row === false) {
            throw new LifecycleException("Unbekanntes Modul: $key");
        }

        return $row;
    }

    private function withLock(callable $fn): mixed
    {
        $conn = $this->conn();
        $got = $conn->execute(
            'SELECT CASE WHEN pg_try_advisory_lock(:k) THEN 1 ELSE 0 END AS ok',
            ['k' => self::LIFECYCLE_LOCK],
        )->fetch('assoc');
        if ((int)($got['ok'] ?? 0) !== 1) {
            throw new LifecycleException('Eine andere Lifecycle-Operation läuft gerade.');
        }
        try {
            return $fn();
        } finally {
            $conn->execute('SELECT pg_advisory_unlock(:k)', ['k' => self::LIFECYCLE_LOCK]);
        }
    }

    private function copyDir(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0o775, true);
        }
        foreach (scandir($src) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            is_dir($src . '/' . $item)
                ? $this->copyDir($src . '/' . $item, $dst . '/' . $item)
                : copy($src . '/' . $item, $dst . '/' . $item);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
