<?php
declare(strict_types=1);

namespace App\Service\Update;

use App\Application;
use App\Audit\AuditLogger;
use App\Infrastructure\Db;
use App\Model\Entity\ContractRegistration;
use App\Service\Module\LifecycleException;
use App\Service\Module\ModuleLifecycle;
use App\Service\Module\ModuleTableRls;
use App\Service\Module\ModuleManifest;
use App\Service\Module\ModuleMigrationRunner;
use App\Service\Registry\ContractRegistry;
use App\Service\Registry\SemVer;
use App\Service\Registry\VersionConstraint;
use App\Service\Security\PackageVerificationException;
use App\Service\Security\PackageVerifier;
use App\Service\Settings\SettingsManager;
use Cake\Database\Connection;
use Migrations\Migrations;
use Throwable;

/**
 * Update manager (Step 8, ch. 28.8/28.9/28.14).
 *
 * Module and core updates with signature verification, compatibility checking, a
 * mandatory recovery point when migrations are present, migration, registry
 * revalidation, audit/history and a rollback cascade.
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

    private function conn(): Connection
    {
        return Db::privileged();
    }

    // ---- Module update -------------------------------------------------------

    /** @return array<string, mixed> */

    /**
     * Migration/compatibility preview for a module update WITHOUT executing it
     * (ch. 24.13 step 8). Returns the version delta, pending migrations and
     * blocking errors (signature/manifest/downgrade).
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
     * Preview for a core update: pending core migrations + incompatible modules
     * (without executing).
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
            $status = (new Migrations(['connection' => Db::privilegedName()]))->status();
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

            $signed = $this->verify($newSourcePath, $manifest);
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
            // Remember already-applied migrations: on failure, ONLY the ones newly
            // applied in THIS update may be rolled back — those already applied at
            // install time stay (otherwise data loss).
            $preApplied = $this->appliedMigrations($moduleId, $newSourcePath . '/migrations');

            $backupPath = $targetPath . '.bak';
            $this->removeDir($backupPath);

            try {
                if ($wasActive) {
                    $this->deactivateRegistrations($key);
                }
                // Swap the code (back up the old state for rollback).
                if (is_dir($targetPath)) {
                    rename($targetPath, $backupPath);
                }
                $this->copyDir($newSourcePath, $targetPath);

                $this->conn()->execute(
                    'UPDATE modules SET version = :v, manifest = CAST(:m AS jsonb) WHERE module_key = :k',
                    ['v' => $newVersion, 'm' => json_encode($manifest->data), 'k' => $key],
                );

                $this->migrations->runUp($moduleId, $schema, $targetPath . '/migrations');
                // Force RLS on any new tables so they stay tenant-gate-conformant
                // (Inc 9c requires FORCE); harmless for the common owner role.
                (new ModuleTableRls())->forceRls($key);

                // Sync the module's contract definitions WITHOUT dropping them.
                // Deleting + recreating them would (via fk_registrations_contract /
                // fk_bindings_contract ON DELETE CASCADE) hard-delete every OTHER
                // module's registration + binding docked onto these contracts — a
                // connector's consumer registration on this extension's service
                // contract, say — and nothing rebuilds those, so the connector goes
                // silently inert after the update. Upserting keeps each contract's
                // stable id, leaving downstream registrations untouched.
                $this->syncProvidedContracts($key, $manifest);
                if ($wasActive) {
                    $this->reactivateRegistrations($key, $manifest, $newVersion);
                    // Purge the module's OWN registrations the new manifest no longer
                    // declares: deactivateRegistrations() marked every prior row
                    // inactive and reactivateRegistrations() re-activated exactly the
                    // current manifest, so any row of this module still inactive is one
                    // the update dropped. Leaving it would let a later provider
                    // reactivation cascade resurrect a consumer the module no longer
                    // declares (the cascade keys on active-module + inactive-consumer).
                    $this->conn()->execute(
                        'DELETE FROM contract_registrations WHERE module_key = :k AND NOT active',
                        ['k' => $key],
                    );
                    $this->conn()->execute("UPDATE modules SET status = 'active' WHERE module_key = :k", ['k' => $key]);
                }

                // Import the NEW version's language files into the managed locale
                // store. The store is version-keyed and the install path imports on
                // install (ModuleLifecycle) — without this, every update left the
                // new version pack-less and the module's i18n domain silently fell
                // back to raw keys (found live after knowledgebase 0.3.1 -> 0.3.2).
                (new ModuleLifecycle())->importPackageLocales($targetPath, $manifest, $signed);

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
                // Rollback cascade (ch. 28.14.2): down migrations -> files ->
                // master data/registry. The pg_dump recovery point is preserved as
                // a manual last resort (no dangerous auto-restore).
                $result = 'failed';
                try {
                    $files = glob($newSourcePath . '/migrations/*.sql') ?: [];
                    rsort($files);
                    foreach ($files as $f) {
                        $name = basename($f);
                        // Only roll back migrations newly applied in this update.
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
                    // Restore the old contract definitions the same non-destructive
                    // way (stable ids -> downstream integrations survive the
                    // rollback too). Only the module's OWN registrations/bindings
                    // are wiped and rebuilt from the old manifest.
                    $this->syncProvidedContracts($key, $oldManifest);
                    $this->conn()->execute('DELETE FROM contract_registrations WHERE module_key = :k', ['k' => $key]);
                    $this->conn()->execute('DELETE FROM capability_bindings WHERE module_key = :k', ['k' => $key]);
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

    // ---- Core update ---------------------------------------------------------

    /**
     * Orchestrates a core update: compatibility check against installed modules,
     * recovery point, core migrations, history/audit.
     *
     * (Swapping the core code itself is done on the environment side; this method
     * performs the migration-/security-relevant orchestration.)
     *
     * @return array<string, mixed>
     */
    public function updateCore(string $targetVersion, bool $force = false): array
    {
        return $this->withLock(function () use ($targetVersion, $force): array {
            SemVer::parse($targetVersion);
            $oldVersion = $this->coreVersion;

            // Compatibility: every installed module must allow targetVersion.
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
                    'Core-Update blockiert, inkompatible Module: ' . implode(', ', $incompatible),
                );
            }

            // Recovery point (a core update may contain migrations).
            $recoveryPath = $this->recovery->create('update_core');

            try {
                // Run pending core migrations (privileged connection because of
                // DDL; the default runs as a NOBYPASSRLS role, E26).
                $migrations = new Migrations(['connection' => Db::privilegedName()]);
                $migrations->migrate();

                $this->recordHistory('core', 'core', $oldVersion, $targetVersion, 'success', null, $recoveryPath);
                $this->audit->log('core.update', 'core', 'core', [
                    'oldValue' => ['version' => $oldVersion],
                    'newValue' => ['version' => $targetVersion],
                ]);

                return ['old_version' => $oldVersion, 'new_version' => $targetVersion, 'recovery_point' => $recoveryPath];
            } catch (Throwable $e) {
                // Core migrations are transactional (cakephp/migrations rolls the
                // failed migration back atomically). No dangerous auto-restore; the
                // recovery point remains for the manual emergency.
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
        return array_values($this->conn()->execute(
            'SELECT component_type, component_key, old_version, new_version, result, executed_at, is_security, severity '
            . 'FROM update_history ORDER BY executed_at DESC LIMIT 20',
        )->fetchAll('assoc'));
    }

    // ---- internal ------------------------------------------------------------

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

    /**
     * Verifies the package signature when required. Returns whether the package
     * was actually verified (false = requirement disabled) — mirrors the install
     * path's signed/reviewed semantics for imported language packs.
     */
    private function verify(string $sourcePath, ModuleManifest $manifest): bool
    {
        if (!(bool)$this->settings->get('core', 'require_module_signature', true)) {
            return false;
        }
        try {
            $this->verifier->verify($sourcePath, $manifest->publisher());
        } catch (PackageVerificationException $e) {
            throw new LifecycleException('Signaturprüfung fehlgeschlagen: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Base names of the **already-applied** migrations contained in the directory
     * (for correctly scoping the update rollback).
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
        // Service providers (public module interfaces, ch. 29): a module that
        // provides a service contract registers its OWN provider row only via
        // services_registered. Omitting it here left that provider registration
        // deactivated after every update, so downstream consumers of the service
        // went inert despite a "successful" update (mirrors activate()).
        foreach ($manifest->servicesRegistered() as $s) {
            $this->registry->register($key, (string)$s['contract'], ContractRegistration::TYPE_PROVIDER, [
                'implementationClass' => $s['class'] ?? null, 'requiredVersion' => $s['version'] ?? null, 'moduleVersion' => $moduleVersion,
            ]);
        }
        foreach ($manifest->contractsUsed() as $u) {
            $this->registry->register($key, (string)$u['contract'], ContractRegistration::TYPE_CONSUMER, [
                'requiredVersion' => $u['version'] ?? null, 'moduleVersion' => $moduleVersion,
            ]);
        }
    }

    /**
     * Idempotently syncs the module's provided contract definitions, keeping each
     * contract's STABLE id across the update. See
     * {@see \App\Service\Registry\ContractRegistry::upsertContract()} for why the
     * delete-and-recreate this replaces was destructive (fk_registrations_contract
     * is ON DELETE CASCADE, so it silently wiped downstream integrations).
     *
     * A contract the module no longer declares is SOFT-deactivated (active=false),
     * NOT hard-deleted: a hard delete would cascade-remove any downstream module's
     * registration + binding docked onto it (silent, and irrecoverable if the update
     * then fails and rolls back — the recreated contract gets a fresh id the orphaned
     * downstream rows no longer reference). Soft-deactivating keeps the stable id, so
     * a rollback's upsertContract(oldManifest) reactivates it and the downstream
     * integration comes back untouched; on a legitimate drop the inactive contract
     * simply stops resolving (contract.active is checked first at resolution).
     */
    private function syncProvidedContracts(string $key, ModuleManifest $manifest): void
    {
        $keep = [];
        foreach ($manifest->contractsProvided() as $c) {
            $name = (string)$c['name'];
            $keep[] = $name;
            $this->registry->upsertContract($key, $name, (string)$c['type'], (string)$c['version'], [
                'description' => $c['description'] ?? null,
                'multiUse' => $c['multi_use'] ?? true,
                'inputSpec' => $c['input_spec'] ?? null,
                'outputSpec' => $c['output_spec'] ?? null,
                'defaultBehavior' => $c['error_behavior'] ?? null,
            ]);
        }
        if ($keep === []) {
            $this->conn()->execute(
                'UPDATE contracts SET active = false, deactivated_at = now() WHERE owner_module_key = :k',
                ['k' => $key],
            );

            return;
        }
        $placeholders = [];
        $params = ['k' => $key];
        foreach ($keep as $i => $name) {
            $placeholders[] = ":n$i";
            $params["n$i"] = $name;
        }
        $this->conn()->execute(
            'UPDATE contracts SET active = false, deactivated_at = now() '
            . 'WHERE owner_module_key = :k AND name NOT IN (' . implode(', ', $placeholders) . ')',
            $params,
        );
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
