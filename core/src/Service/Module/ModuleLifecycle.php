<?php
declare(strict_types=1);

namespace App\Service\Module;

use App\Application;
use App\Audit\AuditLogger;
use App\Model\Entity\ContractRegistration;
use App\Service\I18n\LanguagePackStore;
use App\Service\Registry\ContractRegistry;
use App\Service\Registry\RegistryException;
use App\Service\Registry\SemVer;
use App\Service\License\LicenseService;
use App\Service\Registry\VersionConstraint;
use App\Service\Security\PackageVerificationException;
use App\Service\Security\PackageVerifier;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Modul-Lifecycle (Step 7, Kap. 24): install / activate / deactivate / delete.
 *
 * Jede Operation läuft unter einem PostgreSQL-Advisory-Lock (Kap. 24.18/30.7),
 * sodass lifecycle-verändernde Vorgänge knotenübergreifend serialisiert sind.
 * Der Lifecycle treibt die Contract-Registry aus Step 5 an und auditiert
 * referenzrobust (Step 3). Update = Step 8.
 */
class ModuleLifecycle
{
    private const LIFECYCLE_LOCK = 778899001;

    private ContractRegistry $registry;
    private AuditLogger $audit;
    private ModuleMigrationRunner $migrations;
    private SettingsManager $settings;
    private PackageVerifier $verifier;
    private LicenseService $license;
    private string $coreVersion;

    public function __construct(
        ?ContractRegistry $registry = null,
        ?AuditLogger $audit = null,
        ?ModuleMigrationRunner $migrations = null,
        ?SettingsManager $settings = null,
        ?PackageVerifier $verifier = null,
        ?LicenseService $license = null,
        ?string $coreVersion = null,
    ) {
        $this->registry = $registry ?? new ContractRegistry();
        $this->audit = $audit ?? new AuditLogger();
        $this->migrations = $migrations ?? new ModuleMigrationRunner();
        $this->settings = $settings ?? new SettingsManager();
        $this->verifier = $verifier ?? new PackageVerifier();
        $this->license = $license ?? new LicenseService();
        $this->coreVersion = $coreVersion ?? Application::CORE_VERSION;
    }

    public function license(): LicenseService
    {
        return $this->license;
    }

    private function conn()
    {
        // Modul-Lifecycle macht DDL (CREATE/DROP SCHEMA) -> privilegierte
        // (Superuser-)Connection, die RLS umgeht (E26).
        return \App\Infrastructure\Db::privileged();
    }

    /**
     * Erteilt der NOBYPASSRLS-App-Rolle Rechte auf ein frisch erzeugtes
     * Modul-Schema, damit der Request-Pfad (App-Rolle) darauf zugreifen kann.
     * No-op, wenn keine getrennte App-Rolle konfiguriert ist.
     */
    /**
     * Durchsetzung der RLS-Pflicht für is_scoped-Ressourcen (Kap. 30.3, E47).
     * Deklariert das Modul mindestens eine scoped Ressource, muss sein Schema
     * mindestens eine RLS-aktivierte Tabelle UND mindestens eine Policy
     * enthalten. Andernfalls LifecycleException — der manuelle Rückbau läuft
     * zentral über den try/catch in {@see install()} (E69).
     */
    private function assertScopedRls(string $schema, ModuleManifest $manifest): void
    {
        $scoped = array_filter($manifest->permissions(), static fn ($p) => !empty($p['is_scoped']));
        if ($scoped === []) {
            return;
        }
        $row = $this->conn()->execute(
            'SELECT '
            . '(SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . "  WHERE n.nspname = :s1 AND c.relkind = 'r' AND c.relrowsecurity) AS rls_tables, "
            . '(SELECT count(*) FROM pg_policies WHERE schemaname = :s2) AS policies',
            ['s1' => $schema, 's2' => $schema],
        )->fetch('assoc');
        if ((int)$row['rls_tables'] > 0 && (int)$row['policies'] > 0) {
            return;
        }

        throw new LifecycleException(
            "Modul deklariert is_scoped-Ressourcen, aber Schema $schema enthält keine "
            . 'RLS-geschützte Tabelle mit Policy (Kap. 30.3). Installation abgebrochen.',
        );
    }

    /**
     * Manueller Rückbau aller bis zu einem Fehlschlag angelegten Install-
     * Artefakte (Kap. 24, E69). Der Install ist NICHT in eine DB-Transaktion
     * gekapselt — CREATE ROLE/Schema und das Kopieren des Pakets sind teils
     * nicht-transaktional —, daher räumt {@see install()} bei jedem Throw nach
     * Beginn der Seiteneffekte hier explizit auf: isolierten Host stoppen,
     * provisionierte DB-Rolle entfernen, Schema droppen, Modulzeile samt
     * Registrierungen/Contracts/Ressourcen/Sprachpaketen löschen und das
     * kopierte Verzeichnis (+ Sprachpaket-Dateien) entfernen. So bleibt kein
     * Rest zurück, an dem ein erneuter Install („bereits installiert") scheitert.
     * Jeder Schritt ist best effort und für sich gekapselt.
     */
    private function rollbackInstall(string $key, string $schema, string $targetPath, bool $outOfProcess): void
    {
        // Out-of-Process: etwaigen bereits gestarteten Host stoppen und die
        // eigene DB-Rolle entfernen (DROP OWNED + DROP ROLE), BEVOR das Schema
        // fällt — die Rolle kann Tabellen im Schema besitzen.
        if ($outOfProcess) {
            try {
                (new ModuleHostSupervisor())->stop($key);
            } catch (Throwable) {
                // best effort
            }
            try {
                (new ModuleDbRole())->drop($key);
            } catch (Throwable) {
                // best effort
            }
        }

        $conn = $this->conn();
        $cleanup = [
            "DROP SCHEMA IF EXISTS $schema CASCADE",
            'DELETE FROM contract_registrations WHERE module_key = :k',
            'DELETE FROM contracts WHERE owner_module_key = :k',
            'DELETE FROM resources WHERE module_key = :k',
            'DELETE FROM language_packs WHERE component_key = :k',
            // Modul-Stammdaten zuletzt (CASCADE -> dependencies, migrations_log).
            'DELETE FROM modules WHERE module_key = :k',
        ];
        foreach ($cleanup as $sql) {
            try {
                $conn->execute($sql, str_contains($sql, ':k') ? ['k' => $key] : []);
            } catch (Throwable) {
                // best effort
            }
        }

        // Kopiertes Paketverzeichnis + Sprachpaket-Dateien im Locale Store.
        $this->removeDir($targetPath);
        $this->removeDir((new LanguagePackStore())->base() . '/' . $key);
    }

    private function grantSchemaToAppRole(string $schema): void
    {
        $role = (string)(env('APP_DB_USER') ?: '');
        if ($role === '' || !preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $schema) || !preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role)) {
            return;
        }
        // Nur wenn die Rolle existiert (getrennter Rollenbetrieb aktiv).
        $exists = $this->conn()->execute('SELECT 1 FROM pg_roles WHERE rolname = :r', ['r' => $role])->fetch();
        if ($exists === false) {
            return;
        }
        $c = $this->conn();
        $c->execute("GRANT USAGE ON SCHEMA $schema TO $role");
        $c->execute("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA $schema TO $role");
        $c->execute("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA $schema TO $role");
        $c->execute("ALTER DEFAULT PRIVILEGES IN SCHEMA $schema GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO $role");
        $c->execute("ALTER DEFAULT PRIVILEGES IN SCHEMA $schema GRANT USAGE, SELECT ON SEQUENCES TO $role");
    }

    /**
     * Übernimmt die Paket-Sprachdateien (`<paket>/locales/<locale>/<domain>.po`)
     * in den Managed Locale Store (i18n-4). Domain = Manifest-`locales.domain`
     * (Default Modulschlüssel); signiert -> reviewed (E38). Bei Deinstallation
     * bleiben die Dateien erhalten (kein Gegenstück hier).
     */
    private function importPackageLocales(string $sourcePath, ModuleManifest $manifest, bool $signed): void
    {
        $domain = $manifest->locales()['domain'];
        $base = rtrim($sourcePath, '/') . '/locales';
        if (!is_dir($base)) {
            return;
        }
        $type = $manifest->type() === 'extension' ? 'extension' : 'module';
        $store = new LanguagePackStore();
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $localeDir) {
            $locale = basename($localeDir);
            $poFile = $localeDir . '/' . $domain . '.po';
            if (!is_file($poFile)) {
                continue;
            }
            $store->save($manifest->key(), $manifest->version(), $locale, (string)file_get_contents($poFile), [
                'type' => $type,
                'domain' => $domain,
                'signed' => $signed,
                'reviewed' => $signed,
                'source' => 'package',
            ]);
        }
    }

    public function modulesBaseDir(): string
    {
        return ROOT . DIRECTORY_SEPARATOR . 'modules';
    }

    // ---- öffentliche Operationen --------------------------------------------

    /**
     * @param string $isolation 'in_process' (Standard) oder 'out_of_process'
     *   (Kap. 23.16.2): eigene DB-Rolle, Migrationen unter dieser Rolle, RLS
     *   erzwungen, Aufruf über RPC.
     * @return array<string, mixed>
     */
    public function install(string $sourcePath, string $isolation = 'in_process'): array
    {
        return $this->withLock(function () use ($sourcePath, $isolation): array {
            $sourcePath = rtrim($sourcePath, '/');
            $manifestFile = $sourcePath . '/manifest.json';
            if (!is_file($manifestFile)) {
                throw new LifecycleException("manifest.json fehlt in $sourcePath");
            }
            $manifest = ModuleManifest::fromJson((string)file_get_contents($manifestFile));
            $key = $manifest->key();
            $this->assertKeySafe($key);

            // Signaturprüfung (Step 8): liefert die signierende Schlüssel-ID,
            // damit nachträgliche Widerrufe dem Modul zugeordnet werden können.
            $signatureKeyId = $this->verifySignature($sourcePath, $manifest);

            $errors = $manifest->validate($this->coreVersion);
            if ($errors !== []) {
                $this->audit->log('module.install_failed', 'module', $key, [
                    'newValue' => ['errors' => $errors],
                    'moduleKey' => $key,
                    'moduleName' => $manifest->name(),
                    'moduleVersion' => $manifest->version(),
                ]);
                throw new LifecycleException('Manifest ungültig: ' . implode(' ', $errors));
            }
            if ($this->findModule($key) !== null) {
                throw new LifecycleException("Modul bereits installiert: $key");
            }

            // Out-of-Process früh ablehnen (vor jedem Seiteneffekt), wenn das
            // Modul nicht ausschließlich Service-Contracts anbietet (Kap. 23.16.2).
            if ($isolation === 'out_of_process') {
                $this->assertIsolatable($manifest);
            }

            // Abhängigkeitsprüfung.
            foreach ($manifest->dependencies() as $dep) {
                $depKey = (string)($dep['module'] ?? $dep['id'] ?? '');
                $depVer = $dep['version'] ?? null;
                $depMod = $depKey !== '' ? $this->findModule($depKey) : null;
                if ($depMod === null) {
                    throw new LifecycleException("Abhängigkeit nicht installiert: $depKey");
                }
                if ($depVer !== null
                    && !VersionConstraint::parse($depVer)->isSatisfiedBy(SemVer::parse($depMod['version']))) {
                    throw new LifecycleException(
                        "Abhängigkeit $depKey inkompatibel (gefordert $depVer, vorhanden {$depMod['version']})."
                    );
                }
            }

            $conn = $this->conn();
            $targetPath = $this->modulesBaseDir() . '/' . $key;
            $schema = 'mod_' . $key;

            // Ab hier entstehen Seiteneffekte (Verzeichnis, Schema, Modulzeile,
            // ggf. DB-Rolle/Host). Der Install ist NICHT in eine DB-Transaktion
            // gekapselt — CREATE ROLE/SCHEMA und das Kopieren des Pakets sind
            // teils nicht-transaktional. Wirft irgendein nachfolgender Schritt
            // (grantSchemaToAppRole, importPackageLocales, registerContract …),
            // wird alles bis hierher Angelegte manuell wieder abgeräumt (E69),
            // damit kein Rest zurückbleibt und ein erneuter Install nicht an
            // „bereits installiert" scheitert.
            try {
                $this->copyDir($sourcePath, $targetPath);
                $conn->execute("CREATE SCHEMA IF NOT EXISTS $schema");

                return $this->installArtifacts(
                    $manifest,
                    $key,
                    $schema,
                    $sourcePath,
                    $targetPath,
                    $isolation,
                    $signatureKeyId,
                );
            } catch (Throwable $e) {
                $this->rollbackInstall($key, $schema, $targetPath, $isolation === 'out_of_process');
                throw $e;
            }
        });
    }

    /**
     * Legt die eigentlichen Install-Artefakte an (Modulzeile, Abhängigkeiten,
     * Migrationen, RLS-Pflicht, Rechte, Sprachpakete, Contracts, Ressourcen).
     * Ausgelagert aus {@see install()}, damit der dortige try/catch den
     * manuellen Rückbau ({@see rollbackInstall()}) sauber umschließt.
     *
     * @return array<string, mixed>
     */
    private function installArtifacts(
        ModuleManifest $manifest,
        string $key,
        string $schema,
        string $sourcePath,
        string $targetPath,
        string $isolation,
        ?string $signatureKeyId,
    ): array {
        $conn = $this->conn();
        $row = $conn->execute(
            'INSERT INTO modules (module_key, name, version, type, edition, publisher, php_namespace, '
            . 'core_compatibility, extends_main_module, main_module_compatibility, requires_license, '
            . 'status, manifest, source_path, signature_key_id) VALUES (:key, :name, :ver, :type, :ed, :pub, :ns, :cc, '
            . ":ext, :mmc, :rl, 'installed_inactive', CAST(:man AS jsonb), :sp, :skid) RETURNING id",
            [
                'key' => $key,
                'name' => $manifest->name(),
                'ver' => $manifest->version(),
                'type' => $manifest->type(),
                'ed' => $manifest->edition(),
                'pub' => $manifest->publisher(),
                'ns' => $manifest->phpNamespace(),
                'cc' => $manifest->coreCompatibility(),
                'ext' => $manifest->data['extends_main_module'] ?? null,
                'mmc' => $manifest->data['main_module_compatibility'] ?? null,
                'rl' => $manifest->requiresLicense() ? 'true' : 'false',
                'man' => json_encode($manifest->data),
                'sp' => $targetPath,
                'skid' => $signatureKeyId,
            ],
        )->fetch('assoc');
        $moduleId = (string)$row['id'];

        foreach ($manifest->dependencies() as $dep) {
            $conn->execute(
                'INSERT INTO module_dependencies (module_id, required_module_key, required_version) '
                . 'VALUES (:m, :k, :v)',
                ['m' => $moduleId, 'k' => (string)($dep['module'] ?? $dep['id'] ?? ''), 'v' => $dep['version'] ?? null],
            );
        }

        if ($manifest->phpNamespace() !== null) {
            ModuleAutoloader::register($manifest->phpNamespace(), $targetPath . '/src');
        }

        // Out-of-Process-Isolation (Kap. 23.16.2): eigene DB-Rolle anlegen und
        // die Migrationen UNTER dieser Rolle ausführen (kein Modulcode mit
        // Superuser-Rechten). Nur Service-Contracts sind erlaubt.
        $roleDsn = null;
        if ($isolation === 'out_of_process') {
            $conn->execute("UPDATE modules SET isolation = 'out_of_process' WHERE module_key = :k", ['k' => $key]);
            $role = new ModuleDbRole();
            $role->provision($key);
            $role->grantSchemaCreate($key);
            // Migrationen über die Login-Rolle ausführen (kein Superuser-Code).
            $roleDsn = $role->dsn($key);
        }

        $this->migrations->runUp($moduleId, $schema, $targetPath . '/migrations', $roleDsn);

        // RLS-Pflicht (Kap. 30.3, E47): direkt nach den Migrationen — wer
        // is_scoped-Ressourcen deklariert, muss im Modul-Schema mind. eine
        // RLS-geschützte Tabelle mit Policy mitbringen. Schlägt fehl → wirft;
        // der Rückbau läuft zentral über den try/catch in install() (E69).
        $this->assertScopedRls($schema, $manifest);

        // Isolierte Module: RLS auch für den Tabelleneigentümer (die Modul-
        // Rolle) erzwingen, sonst umginge sie ihre eigene Policy.
        if ($roleDsn !== null) {
            (new ModuleDbRole())->forceRls($key);
        }

        // App-Rolle (NOBYPASSRLS) auf das neue Modul-Schema berechtigen,
        // damit der Request-Pfad nach dem Install darauf zugreifen kann (E26).
        $this->grantSchemaToAppRole($schema);

        // Sprachdateien des Pakets in den Managed Locale Store übernehmen
        // (i18n-4); signiert -> reviewed (E38).
        $this->importPackageLocales($sourcePath, $manifest, $signatureKeyId !== null);

        // contracts_provided als Contract-Definitionen registrieren.
        foreach ($manifest->contractsProvided() as $c) {
            $this->registry->registerContract(
                $key,
                (string)$c['name'],
                (string)$c['type'],
                (string)$c['version'],
                [
                    'description' => $c['description'] ?? null,
                    // Service-Interface-Felder (Kap. 29.5): Mehrfachnutzung +
                    // maschinenlesbare Input-/Output-/Fehler-Spezifikation.
                    'multiUse' => $c['multi_use'] ?? true,
                    'inputSpec' => $c['input_spec'] ?? null,
                    'outputSpec' => $c['output_spec'] ?? null,
                    'defaultBehavior' => $c['error_behavior'] ?? null,
                ],
            );
        }

        // Deklarierte BREAD-Ressourcen registrieren (Step 9, Kap. 25.11).
        foreach ($manifest->permissions() as $p) {
            $conn->execute(
                'INSERT INTO resources (module_key, resource_type, resource_name, description, is_scoped, group_capable, extra_actions) '
                . 'VALUES (:m, :t, :n, :d, :s, :gc, CAST(:e AS jsonb)) '
                . 'ON CONFLICT (module_key, resource_name) DO NOTHING',
                [
                    'm' => $key,
                    't' => (string)($p['resource_type'] ?? ''),
                    'n' => (string)($p['name'] ?? ''),
                    'd' => $p['description'] ?? null,
                    's' => !empty($p['is_scoped']) ? 'true' : 'false',
                    // Gruppenfähig per Default; Modul kann es per Manifest abschalten (Kap. 25.11).
                    'gc' => (!array_key_exists('group_capable', $p) || !empty($p['group_capable'])) ? 'true' : 'false',
                    'e' => isset($p['extra_actions']) ? json_encode($p['extra_actions']) : null,
                ],
            );
        }

        $this->audit->log('module.install', 'module', $key, [
            'newValue' => ['version' => $manifest->version(), 'type' => $manifest->type()],
            'moduleKey' => $key,
            'moduleName' => $manifest->name(),
            'moduleVersion' => $manifest->version(),
        ]);

        return $this->findModule($key) ?? [];
    }

    /** @return array<string, mixed> */
    public function activate(string $key): array
    {
        return $this->withLock(function () use ($key): array {
            $mod = $this->findModuleOrFail($key);
            if (!in_array($mod['status'], ['installed_inactive', 'inactive'], true)) {
                throw new LifecycleException("Modul nicht aktivierbar (Status {$mod['status']}).");
            }
            $manifest = new ModuleManifest(json_decode((string)$mod['manifest'], true) ?: []);

            $errors = $manifest->validate($this->coreVersion);
            if ($errors !== []) {
                throw new LifecycleException('Aktivierung blockiert: ' . implode(' ', $errors));
            }
            // Isolierte Module dürfen nur Service-Contracts anbieten (Kap. 23.16.2),
            // sonst würden Erweiterungspunkte still in-process ausgeführt.
            if (($mod['isolation'] ?? 'in_process') === 'out_of_process') {
                $this->assertIsolatable($manifest);
            }
            foreach ($manifest->dependencies() as $dep) {
                $depKey = (string)($dep['module'] ?? $dep['id'] ?? '');
                $depMod = $depKey !== '' ? $this->findModule($depKey) : null;
                if ($depMod === null || $depMod['status'] !== 'active') {
                    throw new LifecycleException("Abhängigkeit nicht aktiv: $depKey");
                }
            }

            // Lizenz-Gate (Kap. 28.7.2): kostenpflichtige Module ohne gültige
            // Lizenz dürfen nicht aktiviert werden.
            if ($manifest->requiresLicense() && !$this->license->isValid($key)) {
                $ev = $this->license->evaluate($key);
                $this->audit->log('module.license_error', 'module', $key, [
                    'newValue' => ['status' => $ev['status'], 'reason' => $ev['reason'] ?? null],
                    'moduleKey' => $key,
                    'moduleName' => $manifest->name(),
                    'moduleVersion' => (string)$mod['version'],
                ]);
                throw new LifecycleException('Aktivierung blockiert (Lizenz): ' . ($ev['reason'] ?? 'ungültig'));
            }

            if ($manifest->phpNamespace() !== null && $mod['source_path'] !== null) {
                ModuleAutoloader::register($manifest->phpNamespace(), $mod['source_path'] . '/src');
            }

            try {
                foreach ($manifest->resolversRegistered() as $r) {
                    $this->registry->register($key, (string)$r['contract'], ContractRegistration::TYPE_PROVIDER, [
                        'implementationClass' => $r['class'] ?? null,
                        'requiredVersion' => $r['version'] ?? null,
                        'moduleVersion' => $mod['version'],
                    ]);
                }
                foreach ($manifest->collectorsRegistered() as $c) {
                    $this->registry->register($key, (string)$c['contract'], ContractRegistration::TYPE_COLLECTOR, [
                        'implementationClass' => $c['class'] ?? null,
                        'requiredVersion' => $c['version'] ?? null,
                        'priority' => (int)($c['priority'] ?? 0),
                        'moduleVersion' => $mod['version'],
                    ]);
                }
                foreach ($manifest->eventsRegistered() as $e) {
                    $this->registry->register($key, (string)$e['contract'], ContractRegistration::TYPE_LISTENER, [
                        'implementationClass' => $e['class'] ?? null,
                        'requiredVersion' => $e['version'] ?? null,
                        'moduleVersion' => $mod['version'],
                    ]);
                }
                // Service-Anbieter: das anbietende Modul stellt die Implementierung
                // seines öffentlichen Interfaces als PROVIDER bereit (Kap. 29.3.1/29.8).
                foreach ($manifest->servicesRegistered() as $s) {
                    $this->registry->register($key, (string)$s['contract'], ContractRegistration::TYPE_PROVIDER, [
                        'implementationClass' => $s['class'] ?? null,
                        'requiredVersion' => $s['version'] ?? null,
                        'moduleVersion' => $mod['version'],
                    ]);
                }
                foreach ($manifest->contractsUsed() as $u) {
                    $this->registry->register($key, (string)$u['contract'], ContractRegistration::TYPE_CONSUMER, [
                        'requiredVersion' => $u['version'] ?? null,
                        'moduleVersion' => $mod['version'],
                    ]);
                }
            } catch (RegistryException $e) {
                $this->setStatus($key, 'error_activate');
                $this->audit->log('module.activate_failed', 'module', $key, [
                    'newValue' => ['error' => $e->getMessage()],
                    'moduleKey' => $key,
                    'moduleName' => $manifest->name(),
                    'moduleVersion' => $manifest->version(),
                ]);
                throw new LifecycleException('Aktivierung fehlgeschlagen: ' . $e->getMessage(), 0, $e);
            }

            $this->conn()->execute(
                "UPDATE modules SET status = 'active', activated_at = now(), deactivated_at = NULL WHERE module_key = :k",
                ['k' => $key],
            );
            $this->audit->log('module.activate', 'module', $key, [
                'newValue' => ['version' => $mod['version']],
                'moduleKey' => $key,
                'moduleName' => $manifest->name(),
                'moduleVersion' => (string)$mod['version'],
            ]);

            // Out-of-Process: isolierten Host starten (Worker heilt bei Bedarf nach).
            if (($mod['isolation'] ?? 'in_process') === 'out_of_process') {
                try {
                    (new ModuleHostSupervisor())->ensureRunning($key);
                } catch (\Throwable $e) {
                    $this->audit->log('module.host_start_failed', 'module', $key, [
                        'newValue' => ['error' => $e->getMessage()],
                        'moduleKey' => $key,
                    ]);
                }
            }

            return $this->findModule($key) ?? [];
        });
    }

    public function deactivate(string $key): void
    {
        $this->withLock(function () use ($key): void {
            $mod = $this->findModuleOrFail($key);
            if ($mod['status'] !== 'active') {
                throw new LifecycleException("Modul nicht aktiv (Status {$mod['status']}).");
            }
            $this->deactivateRegistrations($key);
            $this->conn()->execute(
                "UPDATE modules SET status = 'inactive', deactivated_at = now() WHERE module_key = :k",
                ['k' => $key],
            );
            // Out-of-Process: isolierten Host stoppen.
            if (($mod['isolation'] ?? 'in_process') === 'out_of_process') {
                try {
                    (new ModuleHostSupervisor())->stop($key);
                } catch (\Throwable) {
                    // best effort
                }
            }

            $this->audit->log('module.deactivate', 'module', $key, [
                'oldValue' => ['status' => 'active'],
                'newValue' => ['status' => 'inactive'],
                'moduleKey' => $key,
                'moduleName' => (string)$mod['name'],
                'moduleVersion' => (string)$mod['version'],
            ]);
        });
    }

    public function delete(string $key): void
    {
        $this->withLock(function () use ($key): void {
            $mod = $this->findModuleOrFail($key);

            // Blockierende Abhängigkeiten: hängt ein anderes Modul von diesem ab?
            $dependents = $this->conn()->execute(
                'SELECT count(*) AS c FROM module_dependencies WHERE required_module_key = :k',
                ['k' => $key],
            )->fetch('assoc');
            if ((int)($dependents['c'] ?? 0) > 0) {
                throw new LifecycleException("Löschen blockiert: andere Module hängen von $key ab.");
            }

            // Out-of-Process: Host stoppen + eigene DB-Rolle entfernen
            // (DROP OWNED löst die Rollen-Objekte/-Rechte, bevor das Schema fällt).
            if (($mod['isolation'] ?? 'in_process') === 'out_of_process') {
                try {
                    (new ModuleHostSupervisor())->stop($key);
                } catch (\Throwable) {
                    // best effort
                }
                (new ModuleDbRole())->drop($key);
            }

            if ($mod['status'] === 'active') {
                $this->deactivateRegistrations($key);
            }

            $conn = $this->conn();
            // Von diesem Modul vorgenommene Registrierungen entfernen.
            $conn->execute('DELETE FROM contract_registrations WHERE module_key = :k', ['k' => $key]);
            // Von diesem Modul definierte Contracts entfernen (CASCADE -> Reg./Bindings).
            $conn->execute('DELETE FROM contracts WHERE owner_module_key = :k', ['k' => $key]);
            // Capability-Bindings dieses Moduls.
            $conn->execute('DELETE FROM capability_bindings WHERE module_key = :k', ['k' => $key]);
            // BREAD-Ressourcen + Rechtezuordnungen dieses Moduls.
            $conn->execute('DELETE FROM group_resource_permissions WHERE module_key = :k', ['k' => $key]);
            $conn->execute('DELETE FROM resources WHERE module_key = :k', ['k' => $key]);
            // Modul-Schema mit allen Modultabellen.
            $schema = 'mod_' . $key;
            $conn->execute("DROP SCHEMA IF EXISTS $schema CASCADE");
            // Modul-Stammdaten (CASCADE -> dependencies, migrations_log).
            $conn->execute('DELETE FROM modules WHERE module_key = :k', ['k' => $key]);

            if ($mod['source_path'] !== null) {
                $this->removeDir((string)$mod['source_path']);
            }

            $this->audit->log('module.delete', 'module', $key, [
                'oldValue' => ['version' => $mod['version'], 'status' => $mod['status']],
                'moduleKey' => $key,
                'moduleName' => (string)$mod['name'],
                'moduleVersion' => (string)$mod['version'],
            ]);
        });
    }

    /** @return list<array<string, mixed>> */
    public function listModules(): array
    {
        return $this->conn()->execute(
            'SELECT module_key, name, version, type, status, isolation FROM modules ORDER BY module_key',
        )->fetchAll('assoc');
    }

    /**
     * Schaltet den Isolationsmodus eines bereits installierten Moduls um
     * (Kap. 23.16.2). out_of_process: eigene DB-Rolle provisionieren + Laufzeit-
     * Rechte auf das (bestehende) Schema erteilen; bei aktivem Modul Host starten.
     * in_process: Host stoppen + Rolle entfernen.
     *
     * @return array<string, mixed>
     */
    public function setIsolation(string $key, string $mode): array
    {
        if (!in_array($mode, ['in_process', 'out_of_process'], true)) {
            throw new LifecycleException("Ungültiger Isolationsmodus: $mode");
        }

        return $this->withLock(function () use ($key, $mode): array {
            $mod = $this->findModuleOrFail($key);
            if ((string)$mod['isolation'] === $mode) {
                return $mod;
            }

            if ($mode === 'out_of_process') {
                $manifest = new ModuleManifest(json_decode((string)$mod['manifest'], true) ?: []);
                $this->assertIsolatable($manifest);
                $role = new ModuleDbRole();
                $role->provision($key);
                // Bestehende Tabellen bleiben Superuser-Eigentum -> Laufzeit-CRUD
                // an die Rolle, RLS greift natürlich (Rolle = NOBYPASSRLS, nicht Eigentümer).
                $role->grantSchemaCrud($key);
                $this->conn()->execute("UPDATE modules SET isolation = 'out_of_process' WHERE module_key = :k", ['k' => $key]);
                if ($mod['status'] === 'active') {
                    try {
                        (new ModuleHostSupervisor())->ensureRunning($key);
                    } catch (\Throwable) {
                        // Worker heilt nach
                    }
                }
            } else {
                try {
                    (new ModuleHostSupervisor())->stop($key);
                } catch (\Throwable) {
                    // best effort
                }
                (new ModuleDbRole())->drop($key);
                $this->conn()->execute(
                    "UPDATE modules SET isolation = 'in_process', db_role = NULL, db_role_secret = NULL WHERE module_key = :k",
                    ['k' => $key],
                );
            }
            $this->audit->log('module.set_isolation', 'module', $key, [
                'newValue' => ['isolation' => $mode],
                'moduleKey' => $key,
                'moduleName' => (string)$mod['name'],
                'moduleVersion' => (string)$mod['version'],
            ]);

            return $this->findModule($key) ?? [];
        });
    }

    // ---- intern --------------------------------------------------------------

    /**
     * Out-of-Process unterstützt derzeit nur Service-Contracts. Deklariert ein
     * Modul Resolver/Collector/Event-Listener, würden diese still in-process
     * laufen — daher wird die Isolation abgelehnt (keine stille Lücke).
     */
    private function assertIsolatable(ModuleManifest $manifest): void
    {
        // Phase 3 (Kap. 23.16.2): Service-Contracts, Collector (Health/Anonymize
        // u. a.) und Event-Listener laufen über RPC. Noch NICHT über RPC und daher
        // bei Isolation abgelehnt: Resolver und periodische Aufgaben
        // (Collector `core.collector.scheduled`) — beide würden sonst still
        // in-process ausgeführt.
        $bad = [];
        if ($manifest->resolversRegistered() !== []) {
            $bad[] = 'Resolver';
        }
        foreach ($manifest->collectorsRegistered() as $c) {
            if ((string)($c['contract'] ?? '') === \App\Service\Schedule\ScheduledTaskRunner::COLLECTOR) {
                $bad[] = 'periodische Aufgabe (core.collector.scheduled)';
                break;
            }
        }
        if ($bad !== []) {
            throw new LifecycleException(
                'Out-of-Process unterstützt diese Erweiterungspunkte noch nicht über RPC: '
                . implode(', ', $bad) . '. Sie würden sonst still in-process ausgeführt.',
            );
        }
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

    /** @return array<string, mixed>|null */
    private function findModule(string $key): ?array
    {
        $row = $this->conn()->execute(
            'SELECT * FROM modules WHERE module_key = :k',
            ['k' => $key],
        )->fetch('assoc');

        return $row ?: null;
    }

    /** @return array<string, mixed> */
    private function findModuleOrFail(string $key): array
    {
        $mod = $this->findModule($key);
        if ($mod === null) {
            throw new LifecycleException("Unbekanntes Modul: $key");
        }

        return $mod;
    }

    private function setStatus(string $key, string $status): void
    {
        $this->conn()->execute('UPDATE modules SET status = :s WHERE module_key = :k', ['s' => $status, 'k' => $key]);
    }

    private function assertKeySafe(string $key): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,62}$/', $key)) {
            throw new LifecycleException("Ungültiger module_key: \"$key\" (a-z, 0-9, _; mit Buchstabe beginnend).");
        }
    }

    private function verifySignature(string $sourcePath, ModuleManifest $manifest): ?string
    {
        if (!(bool)$this->settings->get('core', 'require_module_signature', true)) {
            return null; // Dev-Bypass (Setting)
        }
        try {
            $result = $this->verifier->verify($sourcePath, $manifest->publisher());

            return $result['key_id'] ?? null;
        } catch (PackageVerificationException $e) {
            $this->audit->log('module.signature_invalid', 'module', $manifest->key(), [
                'newValue' => ['error' => $e->getMessage()],
                'moduleKey' => $manifest->key(),
                'moduleName' => $manifest->name(),
                'moduleVersion' => $manifest->version(),
            ]);
            throw new LifecycleException('Signaturprüfung fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private function withLock(callable $fn): mixed
    {
        $conn = $this->conn();
        $got = $conn->execute(
            'SELECT CASE WHEN pg_try_advisory_lock(:k) THEN 1 ELSE 0 END AS ok',
            ['k' => self::LIFECYCLE_LOCK],
        )->fetch('assoc');
        if ((int)($got['ok'] ?? 0) !== 1) {
            throw new LifecycleException('Eine andere Lifecycle-Operation läuft gerade. Bitte erneut versuchen.');
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
        $items = scandir($src) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $from = $src . '/' . $item;
            $to = $dst . '/' . $item;
            if (is_dir($from)) {
                $this->copyDir($from, $to);
            } else {
                copy($from, $to);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
