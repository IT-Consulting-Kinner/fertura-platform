<?php
declare(strict_types=1);

namespace App\Service\Module;

use App\Application;
use App\Audit\AuditLogger;
use App\Infrastructure\Db;
use App\Model\Entity\ContractRegistration;
use App\Service\I18n\LanguagePackStore;
use App\Service\License\LicenseService;
use App\Service\Registry\ContractRegistry;
use App\Service\Registry\RegistryException;
use App\Service\Registry\SemVer;
use App\Service\Registry\VersionConstraint;
use App\Service\Sdk\ManifestLinter;
use App\Service\Security\PackageVerificationException;
use App\Service\Security\PackageVerifier;
use App\Service\Settings\SettingsManager;
use Cake\Database\Connection;
use Throwable;

/**
 * Module lifecycle (Step 7, ch. 24): install / activate / deactivate / delete.
 *
 * Every operation runs under a PostgreSQL advisory lock (ch. 24.18/30.7), so
 * lifecycle-changing operations are serialized across nodes. The lifecycle
 * drives the contract registry from Step 5 and audits reference-robustly
 * (Step 3). Update = Step 8.
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

    private function conn(): Connection
    {
        // The module lifecycle performs DDL (CREATE/DROP SCHEMA) -> privileged
        // (superuser) connection that bypasses RLS (E26).
        return Db::privileged();
    }

    /**
     * Grants the NOBYPASSRLS app role privileges on a freshly created module
     * schema, so the request path (app role) can access it. No-op if no separate
     * app role is configured.
     */

    /**
     * Tenant-conformance gate (Inc 9c/9e, ch. 24/30.3, E47) — the DB analog of the
     * per-module file convention (Inc 8). EVERY base table in a module's schema must be
     * either:
     *   - tenant-conformant — see {@see self::tenantTableViolation()} — or
     *   - declared module-global in the manifest `tables` section (`scope: global`),
     *     the auditable opt-out for genuine reference/lookup data.
     * Any other table is a forgotten tenant dimension: a cross-tenant leak that is also
     * invisible to per-tenant backup + consumption (the discovery query keys on the same
     * tenant_id+RLS shape). On violation it throws a LifecycleException; the manual
     * rollback is handled centrally by the try/catch in {@see install()} (E69).
     *
     * The check is NOT gated on the module declaring is_scoped resources (Inc 9e): a
     * module can own tenant-bearing tables even when its permission is global (e.g. the
     * ticketing↔KB connector — global capability, tenant-scoped link data), so keying
     * the gate on is_scoped would silently skip it. is_scoped only adds one extra rule:
     * a module that DOES declare a scoped resource must own at least one table (E47).
     *
     * This is a STRUCTURAL gate: it proves a table HAS the tenant shape, not that the
     * policy's logic is flawless (a policy could reference the wrong column) — that
     * residual is covered by the modules' mandatory NOBYPASSRLS leak tests.
     */
    private function assertTenantTablesConform(string $schema, ModuleManifest $manifest): void
    {
        $scoped = array_filter($manifest->permissions(), static fn($p) => !empty($p['is_scoped']));
        $global = $manifest->globalTables();
        $tables = $this->conn()->execute(
            'SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . "WHERE n.nspname = :s AND c.relkind = 'r' ORDER BY c.relname",
            ['s' => $schema],
        )->fetchAll('assoc');
        // A module declaring a scoped resource must own at least one table (E47).
        if ($scoped !== [] && $tables === []) {
            throw new LifecycleException(
                "Modul deklariert is_scoped-Ressourcen, aber Schema $schema enthält keine Tabelle "
                . '(Kap. 30.3). Installation abgebrochen.',
            );
        }

        $violations = [];
        foreach ($tables as $row) {
            $table = (string)$row['relname'];
            if (in_array($table, $global, true)) {
                continue; // declared module-global exception
            }
            $problem = $this->tenantTableViolation($schema, $table);
            if ($problem !== null) {
                $violations[] = "$table: $problem";
            }
        }
        if ($violations === []) {
            return;
        }

        throw new LifecycleException(
            "Modul-Tabellen verletzen die Mandanten-Konvention (Kap. 24/30.3); Installation abgebrochen:\n- "
            . implode("\n- ", $violations)
            . "\n\nEntweder die Tabelle tenant-konform machen (tenant_id uuid NOT NULL DEFAULT core.current_tenant(), "
            . 'RLS ENABLE+FORCE, Policy "core.rls_bypass() OR tenant_id = core.current_tenant()" in USING und WITH CHECK, '
            . 'UNIQUEs mit tenant_id) — am einfachsten via core.create_tenant_table()/core.add_tenant_unique() — '
            . 'oder im Manifest als tables[].scope=global (mit reason) deklarieren.',
        );
    }

    /**
     * Returns the FIRST tenant-conformance violation of $schema.$table, or null when it
     * is fully conformant. Checks the live catalog (so it is correct regardless of which
     * migration produced the table): (1) a `tenant_id` uuid column, NOT NULL, with a
     * DEFAULT referencing core.current_tenant(); (2) RLS enabled AND forced; (3) a policy
     * whose USING and a policy whose WITH CHECK both reference tenant_id together with
     * core.current_tenant() (rejecting USING(true)/permissive); (4) every secondary
     * UNIQUE index keyed with tenant_id (a global unique is a cross-tenant existence
     * oracle). RESTRICTIVE tenant policies layered on top of an area policy satisfy (3)
     * because the restrictive policy's expression references both tokens.
     */
    private function tenantTableViolation(string $schema, string $table): ?string
    {
        $conn = $this->conn();
        $col = $conn->execute(
            'SELECT a.attnotnull, t.typname, pg_get_expr(d.adbin, d.adrelid) AS dflt '
            . 'FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid '
            . 'JOIN pg_namespace n ON n.oid = c.relnamespace JOIN pg_type t ON t.oid = a.atttypid '
            . 'LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum '
            . "WHERE n.nspname = :s AND c.relname = :t AND a.attname = 'tenant_id' "
            . 'AND a.attnum > 0 AND NOT a.attisdropped',
            ['s' => $schema, 't' => $table],
        )->fetch('assoc');
        if ($col === false) {
            return 'keine tenant_id-Spalte';
        }
        if ((string)$col['typname'] !== 'uuid') {
            return 'tenant_id ist nicht uuid (' . (string)$col['typname'] . ')';
        }
        if (!($col['attnotnull'] === true || $col['attnotnull'] === 't')) {
            return 'tenant_id ist nullable (muss NOT NULL sein)';
        }
        if (!str_contains((string)$col['dflt'], 'current_tenant')) {
            return 'tenant_id ohne DEFAULT core.current_tenant()';
        }

        $rls = $conn->execute(
            'SELECT c.relrowsecurity, c.relforcerowsecurity FROM pg_class c '
            . 'JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = :s AND c.relname = :t',
            ['s' => $schema, 't' => $table],
        )->fetch('assoc');
        if (!($rls['relrowsecurity'] === true || $rls['relrowsecurity'] === 't')) {
            return 'RLS nicht aktiviert (ENABLE ROW LEVEL SECURITY fehlt)';
        }
        if (!($rls['relforcerowsecurity'] === true || $rls['relforcerowsecurity'] === 't')) {
            return 'RLS nicht erzwungen (FORCE ROW LEVEL SECURITY fehlt)';
        }

        $policies = $conn->execute(
            'SELECT qual, with_check FROM pg_policies WHERE schemaname = :s AND tablename = :t',
            ['s' => $schema, 't' => $table],
        )->fetchAll('assoc');
        $hasUsing = false;
        $hasCheck = false;
        foreach ($policies as $p) {
            if ($this->policyRefsTenant((string)($p['qual'] ?? ''))) {
                $hasUsing = true;
            }
            if ($this->policyRefsTenant((string)($p['with_check'] ?? ''))) {
                $hasCheck = true;
            }
        }
        if (!$hasUsing) {
            return 'keine Policy, deren USING tenant_id an core.current_tenant() bindet';
        }
        if (!$hasCheck) {
            return 'keine Policy, deren WITH CHECK tenant_id an core.current_tenant() bindet';
        }

        $uniques = $conn->execute(
            'SELECT i.relname AS idxname, (SELECT bool_or(att.attname = \'tenant_id\') '
            . 'FROM unnest(ix.indkey) WITH ORDINALITY k(attnum, ord) '
            . 'JOIN pg_attribute att ON att.attrelid = ix.indrelid AND att.attnum = k.attnum) AS has_tenant '
            . 'FROM pg_index ix JOIN pg_class i ON i.oid = ix.indexrelid '
            . 'JOIN pg_class c ON c.oid = ix.indrelid JOIN pg_namespace n ON n.oid = c.relnamespace '
            . 'WHERE n.nspname = :s AND c.relname = :t AND ix.indisunique AND NOT ix.indisprimary',
            ['s' => $schema, 't' => $table],
        )->fetchAll('assoc');
        foreach ($uniques as $u) {
            if (!($u['has_tenant'] === true || $u['has_tenant'] === 't')) {
                return 'UNIQUE-Index ' . (string)$u['idxname'] . ' enthält tenant_id nicht (Cross-Tenant-Kollision/Orakel)';
            }
        }

        return null;
    }

    /**
     * Whether an RLS policy expression actually binds the tenant: it must reference BOTH
     * `tenant_id` and `current_tenant` (i.e. the canonical `tenant_id = core.current_tenant()`),
     * so a permissive `USING (true)` or a `current_tenant() IS NOT NULL` that omits the
     * column does not count.
     */
    private function policyRefsTenant(string $expr): bool
    {
        return str_contains($expr, 'tenant_id') && str_contains($expr, 'current_tenant');
    }

    /**
     * Manual rollback of all install artifacts created up to a failure (ch. 24,
     * E69). The install is NOT wrapped in a DB transaction — CREATE schema and
     * copying the package are partly non-transactional — so on any throw after
     * side effects have begun, {@see install()} explicitly cleans up here: drop
     * the schema, delete the module row together with its
     * registrations/contracts/resources/language packs, and remove the copied
     * directory (+ language pack files). This leaves no remnant that would make a
     * subsequent install fail with "already installed". Each step is best effort
     * and self-contained.
     */
    private function rollbackInstall(string $key, string $schema, string $targetPath): void
    {
        $conn = $this->conn();
        $cleanup = [
            "DROP SCHEMA IF EXISTS $schema CASCADE",
            'DELETE FROM contract_registrations WHERE module_key = :k',
            'DELETE FROM contracts WHERE owner_module_key = :k',
            'DELETE FROM resources WHERE module_key = :k',
            'DELETE FROM language_packs WHERE component_key = :k',
            // Module master record last (CASCADE -> dependencies, migrations_log).
            'DELETE FROM modules WHERE module_key = :k',
        ];
        foreach ($cleanup as $sql) {
            try {
                $conn->execute($sql, str_contains($sql, ':k') ? ['k' => $key] : []);
            } catch (Throwable) {
                // best effort
            }
        }

        // Copied package directory + language pack files in the locale store.
        $this->removeDir($targetPath);
        $this->removeDir((new LanguagePackStore())->base() . '/' . $key);
    }

    /**
     * Public action-own rollback for the maintenance critical-action runner (Phase 6):
     * removes an installed/half-installed module by key. install() only self-rolls-back
     * on its OWN execute failure; this lets the runner undo a module whose post-install
     * VERIFY failed. Derives schema/target from the key and delegates to the same
     * best-effort {@see rollbackInstall()} cleanup, under the lifecycle lock.
     */
    public function purge(string $moduleKey): void
    {
        $this->assertKeySafe($moduleKey);
        $this->withLock(function () use ($moduleKey): void {
            $this->rollbackInstall(
                $moduleKey,
                'mod_' . $moduleKey,
                $this->modulesBaseDir() . '/' . $moduleKey,
            );
        });
    }

    private function grantSchemaToAppRole(string $schema): void
    {
        $role = (string)(env('APP_DB_USER') ?: '');
        if ($role === '' || !preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $schema) || !preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role)) {
            return;
        }
        // Only if the role exists (separate-role operation is active).
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
     * Imports the package language files (`<package>/locales/<locale>/<domain>.po`)
     * into the managed locale store (i18n-4). Domain = manifest `locales.domain`
     * (defaults to the module key); signed -> reviewed (E38). On uninstall the
     * files are kept (there is no counterpart here).
     *
     * Public because the update path (UpdateManager::updateModule) must import
     * the NEW version's pack too — the store is version-keyed, so without this
     * every module update left the new version without a language pack and the
     * module's i18n domain silently degraded to raw keys.
     */
    public function importPackageLocales(string $sourcePath, ModuleManifest $manifest, bool $signed): void
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

    // ---- public operations --------------------------------------------------

    /**
     * Installs a module from a (signed) package directory. Modules run in-process;
     * trust is established at install time (signature + review + the capability gate).
     *
     * @return array<string, mixed>
     */
    public function install(string $sourcePath): array
    {
        return $this->withLock(function () use ($sourcePath): array {
            $sourcePath = rtrim($sourcePath, '/');
            $manifestFile = $sourcePath . '/manifest.json';
            if (!is_file($manifestFile)) {
                throw new LifecycleException("manifest.json fehlt in $sourcePath");
            }
            $manifest = ModuleManifest::fromJson((string)file_get_contents($manifestFile));
            $key = $manifest->key();
            $this->assertKeySafe($key);

            // Signature check (Step 8): returns the signing key ID, so later
            // revocations can be attributed to the module.
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

            // Capability gate (Inc 10): an in-process module must not use dangerous
            // primitives (shell/eval/reflection/raw FS/raw DB). Checked BEFORE any side
            // effect — trust is established at install time (signature + review + this
            // gate), so a non-conforming package is refused. (The storage-convention
            // check is enforced separately at runtime + by module_lint, not here.)
            $capErrors = (new ManifestLinter())->lintCapabilities($sourcePath);
            if ($capErrors !== []) {
                throw new LifecycleException('Modul-Quellcode abgelehnt (Capability-Gate): ' . implode(' | ', $capErrors));
            }

            // Dependency check.
            foreach ($manifest->dependencies() as $dep) {
                $depKey = (string)($dep['module'] ?? $dep['id'] ?? '');
                $depVer = $dep['version'] ?? null;
                $depMod = $depKey !== '' ? $this->findModule($depKey) : null;
                if ($depMod === null) {
                    throw new LifecycleException("Abhängigkeit nicht installiert: $depKey");
                }
                if (
                    $depVer !== null
                    && !VersionConstraint::parse($depVer)->isSatisfiedBy(SemVer::parse($depMod['version']))
                ) {
                    throw new LifecycleException(
                        "Abhängigkeit $depKey inkompatibel (gefordert $depVer, vorhanden {$depMod['version']}).",
                    );
                }
            }

            $conn = $this->conn();
            $targetPath = $this->modulesBaseDir() . '/' . $key;
            $schema = 'mod_' . $key;

            // From here on side effects are produced (directory, schema, module
            // row, possibly DB role/host). The install is NOT wrapped in a DB
            // transaction — CREATE ROLE/SCHEMA and copying the package are partly
            // non-transactional. If any subsequent step throws
            // (grantSchemaToAppRole, importPackageLocales, registerContract …),
            // everything created up to that point is manually cleaned up (E69),
            // so no remnant remains and a subsequent install does not fail with
            // "already installed".
            try {
                $this->copyDir($sourcePath, $targetPath);
                $conn->execute("CREATE SCHEMA IF NOT EXISTS $schema");

                return $this->installArtifacts(
                    $manifest,
                    $key,
                    $schema,
                    $sourcePath,
                    $targetPath,
                    $signatureKeyId,
                );
            } catch (Throwable $e) {
                $this->rollbackInstall($key, $schema, $targetPath);
                throw $e;
            }
        });
    }

    /**
     * Creates the actual install artifacts (module row, dependencies, migrations,
     * RLS requirement, privileges, language packs, contracts, resources).
     * Extracted from {@see install()} so that its try/catch cleanly wraps the
     * manual rollback ({@see rollbackInstall()}).
     *
     * @return array<string, mixed>
     */
    private function installArtifacts(
        ModuleManifest $manifest,
        string $key,
        string $schema,
        string $sourcePath,
        string $targetPath,
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

        $this->migrations->runUp($moduleId, $schema, $targetPath . '/migrations');

        // Force RLS on every RLS-enabled module table BEFORE the conformance gate, so
        // the policy binds the table owner too (harmless for the common BYPASSRLS
        // migration owner; essential whenever the owning role is NOBYPASSRLS) and the
        // gate's FORCE requirement is satisfiable.
        (new ModuleTableRls())->forceRls($key);

        // Tenant-conformance gate (Inc 9c, ch. 24/30.3, E47): a module declaring
        // is_scoped resources must have EVERY table in its schema either tenant-conformant
        // (tenant_id + RLS enabled/forced + a tenant policy + tenant-scoped uniques) or
        // explicitly declared module-global in the manifest. Otherwise activation is
        // refused — so a forgotten tenant dimension can never ship as a silent
        // cross-tenant leak (and a non-discoverable, unbilled table). On failure it
        // throws; the rollback is handled centrally by the try/catch in install() (E69).
        $this->assertTenantTablesConform($schema, $manifest);

        // Authorize the app role (NOBYPASSRLS) on the new module schema, so the
        // request path can access it after the install (E26).
        $this->grantSchemaToAppRole($schema);

        // Import the package's language files into the managed locale store
        // (i18n-4); signed -> reviewed (E38).
        $this->importPackageLocales($sourcePath, $manifest, $signatureKeyId !== null);

        // Register contracts_provided as contract definitions.
        foreach ($manifest->contractsProvided() as $c) {
            $this->registry->registerContract(
                $key,
                (string)$c['name'],
                (string)$c['type'],
                (string)$c['version'],
                [
                    'description' => $c['description'] ?? null,
                    // Service interface fields (ch. 29.5): multi-use + machine-
                    // readable input/output/error specification.
                    'multiUse' => $c['multi_use'] ?? true,
                    'inputSpec' => $c['input_spec'] ?? null,
                    'outputSpec' => $c['output_spec'] ?? null,
                    'defaultBehavior' => $c['error_behavior'] ?? null,
                ],
            );
        }

        // Register the declared BREAD resources (Step 9, ch. 25.11).
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
                    // Group-capable by default; a module can disable it via the manifest (ch. 25.11).
                    'gc' => !array_key_exists('group_capable', $p) || !empty($p['group_capable']) ? 'true' : 'false',
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
            foreach ($manifest->dependencies() as $dep) {
                $depKey = (string)($dep['module'] ?? $dep['id'] ?? '');
                $depMod = $depKey !== '' ? $this->findModule($depKey) : null;
                if ($depMod === null || $depMod['status'] !== 'active') {
                    throw new LifecycleException("Abhängigkeit nicht aktiv: $depKey");
                }
            }
            // Integration-extension module (connector, ch. 23.5.2/28.12): every
            // bridged main module declared in integration_relations must be active
            // (and version-compatible, if a constraint is given), else the connector
            // does not activate.
            foreach ($manifest->integrationRelations() as $rel) {
                $relKey = (string)($rel['module'] ?? $rel['id'] ?? '');
                $relMod = $relKey !== '' ? $this->findModule($relKey) : null;
                if ($relMod === null || $relMod['status'] !== 'active') {
                    throw new LifecycleException("Integrations-Beziehung nicht aktiv: $relKey");
                }
                $constraint = (string)($rel['compatibility'] ?? $rel['version'] ?? '');
                if ($constraint !== '' && !$this->relationSatisfied($constraint, (string)$relMod['version'])) {
                    throw new LifecycleException(
                        "Integrations-Beziehung $relKey: Version {$relMod['version']} erfüllt $constraint nicht.",
                    );
                }
            }

            // License gate (ch. 28.7.2): paid modules without a valid license must
            // not be activated.
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
                // Service provider: the providing module exposes the implementation
                // of its public interface as a PROVIDER (ch. 29.3.1/29.8).
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
                // Register the module's admin areas (web-mount) so they are
                // grantable (FK target of user_admin_areas), ch. 23.16.3.
                $this->registerAdminAreas($manifest);
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

            return $this->findModule($key) ?? [];
        });
    }

    /**
     * Registers the module's web-mount admin areas in `core.admin_areas` so they
     * become grantable (the FK target of `user_admin_areas`). Idempotent; module
     * areas sort after the Core areas (ch. 23.16.3).
     */
    private function registerAdminAreas(ModuleManifest $manifest): void
    {
        $i = 0;
        foreach ($manifest->adminAreas() as $areaKey => $label) {
            $this->conn()->execute(
                'INSERT INTO admin_areas (area_key, label, sort_order) VALUES (:k, :l, :s) '
                . 'ON CONFLICT (area_key) DO NOTHING',
                ['k' => $areaKey, 'l' => $label, 's' => 1000 + $i++],
            );
        }
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

            // Blocking dependencies: does another module depend on this one?
            $dependents = $this->conn()->execute(
                'SELECT count(*) AS c FROM module_dependencies WHERE required_module_key = :k',
                ['k' => $key],
            )->fetch('assoc');
            if ((int)($dependents['c'] ?? 0) > 0) {
                throw new LifecycleException("Löschen blockiert: andere Module hängen von $key ab.");
            }

            if ($mod['status'] === 'active') {
                $this->deactivateRegistrations($key);
            }

            $conn = $this->conn();
            // Remove the registrations made by this module.
            $conn->execute('DELETE FROM contract_registrations WHERE module_key = :k', ['k' => $key]);
            // Remove the module's web-mount admin areas + any grants to them
            // (grants first: user_admin_areas FK is ON DELETE RESTRICT).
            $delManifest = new ModuleManifest(json_decode((string)$mod['manifest'], true) ?: []);
            foreach (array_keys($delManifest->adminAreas()) as $areaKey) {
                $conn->execute('DELETE FROM user_admin_areas WHERE admin_area_key = :a', ['a' => $areaKey]);
                $conn->execute('DELETE FROM admin_areas WHERE area_key = :a', ['a' => $areaKey]);
            }
            // Remove the contracts defined by this module (CASCADE -> registrations/bindings).
            $conn->execute('DELETE FROM contracts WHERE owner_module_key = :k', ['k' => $key]);
            // This module's capability bindings.
            $conn->execute('DELETE FROM capability_bindings WHERE module_key = :k', ['k' => $key]);
            // This module's BREAD resources + permission assignments.
            $conn->execute('DELETE FROM group_resource_permissions WHERE module_key = :k', ['k' => $key]);
            $conn->execute('DELETE FROM resources WHERE module_key = :k', ['k' => $key]);
            // Module schema with all module tables.
            $schema = 'mod_' . $key;
            $conn->execute("DROP SCHEMA IF EXISTS $schema CASCADE");
            // Module master record (CASCADE -> dependencies, migrations_log).
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
        return array_values($this->conn()->execute(
            'SELECT module_key, name, version, type, status FROM modules ORDER BY module_key',
        )->fetchAll('assoc'));
    }

    // ---- internal ------------------------------------------------------------

    private function deactivateRegistrations(string $key): void
    {
        // Deactivate the module's own active registrations, remembering which
        // contracts it provided so the cascade can check whether each one just
        // lost its last provider.
        $rows = $this->conn()->execute(
            'SELECT id, contract_id, registration_type FROM contract_registrations WHERE module_key = :k AND active',
            ['k' => $key],
        )->fetchAll('assoc');
        $providedContractIds = [];
        foreach ($rows as $row) {
            if ((string)$row['registration_type'] === ContractRegistration::TYPE_PROVIDER) {
                $providedContractIds[(string)$row['contract_id']] = true;
            }
            $this->registry->deactivateRegistration((string)$row['id']);
        }

        $this->cascadeDeactivateIntegrations($key, array_keys($providedContractIds));
    }

    /**
     * Deactivation cascade (Decision 149 / ch. 23.5.5): when a module that
     * provided a contract is deactivated and no other active provider remains,
     * the integrations docking onto that contract — other modules' CONSUMER
     * registrations, e.g. a connector docked onto an extension's contract —
     * become non-functional. They are marked inactive so the registry matches
     * the runtime, where {@see \App\Service\Registry\CapabilityHandle::invoke()}
     * would already reject the call and the contract's default behavior applies.
     *
     * The consuming modules themselves stay active — their base operability must
     * not be destroyed (Decision 149); only their docking registration is
     * severed. Connectors are leaf nodes (they never provide contracts), so the
     * cascade never recurses past this one level (ch. 23.5.5).
     *
     * @param list<string> $providedContractIds Contracts the deactivated module provided.
     */
    private function cascadeDeactivateIntegrations(string $triggerKey, array $providedContractIds): void
    {
        foreach ($providedContractIds as $contractId) {
            // Another active provider still serves the contract -> the docking
            // integrations remain functional and must be left untouched.
            $stillProvided = $this->conn()->execute(
                'SELECT 1 FROM contract_registrations '
                . 'WHERE contract_id = :c AND registration_type = :t AND active LIMIT 1',
                ['c' => $contractId, 't' => ContractRegistration::TYPE_PROVIDER],
            )->fetch('assoc');
            if ($stillProvided !== false) {
                continue;
            }

            $consumers = $this->conn()->execute(
                'SELECT cr.id, cr.module_key, c.name AS contract_name '
                . 'FROM contract_registrations cr JOIN contracts c ON c.id = cr.contract_id '
                . 'WHERE cr.contract_id = :c AND cr.registration_type = :t AND cr.active',
                ['c' => $contractId, 't' => ContractRegistration::TYPE_CONSUMER],
            )->fetchAll('assoc');

            foreach ($consumers as $consumer) {
                // deactivateRegistration() also revokes the consumer's capability
                // binding, so handleFor() returns null afterwards (the consumer
                // cleanly falls back to the default instead of holding a handle
                // that throws on invoke()).
                $this->registry->deactivateRegistration((string)$consumer['id']);
                $this->audit->log(
                    'module.integration_deactivated',
                    'contract_registration',
                    (string)$consumer['contract_name'],
                    [
                        'oldValue' => ['active' => true],
                        'newValue' => [
                            'active' => false,
                            'reason' => 'provider_deactivated',
                            'triggerModule' => $triggerKey,
                        ],
                        'moduleKey' => (string)$consumer['module_key'],
                    ],
                );
            }
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

    /** Whether a module version satisfies an integration-relation version constraint. */
    private function relationSatisfied(string $constraint, string $version): bool
    {
        try {
            return VersionConstraint::parse($constraint)->isSatisfiedBy(SemVer::parse($version));
        } catch (Throwable) {
            return false;
        }
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
            return null; // dev bypass (setting)
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
