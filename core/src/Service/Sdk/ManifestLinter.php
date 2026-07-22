<?php
declare(strict_types=1);

namespace App\Service\Sdk;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Manifest linter (program tier 3, P16): static validation of a module manifest
 * (without DB/core version) — required fields, formats, and the shape of the
 * registration sections. Complements {@see \App\Service\Module\ModuleManifest}
 * (runtime/compatibility check) with a developer-friendly early check.
 */
class ManifestLinter
{
    private const REQUIRED = ['id', 'name', 'version', 'type', 'edition', 'description', 'core_compatibility', 'publisher', 'php_namespace'];
    private const SECTIONS = ['collectors_registered', 'resolvers_registered', 'services_registered', 'events_registered'];

    /**
     * @param array<string,mixed> $m
     * @return array{errors:list<string>, warnings:list<string>}
     */
    public function lint(array $m): array
    {
        $errors = [];
        $warnings = [];

        foreach (self::REQUIRED as $field) {
            if (empty($m[$field])) {
                $errors[] = "Pflichtfeld fehlt oder leer: $field";
            }
        }
        if (isset($m['id']) && !preg_match('/^[a-z][a-z0-9_]*$/', (string)$m['id'])) {
            $errors[] = "id ungültig (erlaubt: [a-z][a-z0-9_]*): {$m['id']}";
        }
        $ns = (string)($m['php_namespace'] ?? '');
        if ($ns !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $ns)) {
            $errors[] = "php_namespace ungültig: $ns";
        }
        if (isset($m['version']) && !preg_match('/^\d+\.\d+\.\d+/', (string)$m['version'])) {
            $warnings[] = "version sollte SemVer sein: {$m['version']}";
        }
        if (isset($m['type']) && !in_array($m['type'], ['main', 'extension', 'integration'], true)) {
            $warnings[] = "type unüblich (erwartet main|extension|integration): {$m['type']}";
        }
        // Integration-extension module (connector, ch. 23.5.2): needs integration
        // relations and, as a LEAF NODE (ch. 23.5.5), must provide no contracts.
        if (($m['type'] ?? '') === 'integration') {
            if (empty($m['integration_relations'])) {
                $errors[] = 'Integrations-Extension-Modul: integration_relations fehlt (mind. ein Main-Modul)';
            }
            if (
                !empty($m['contracts_provided'])
                || !empty($m['resolvers_registered'])
                || !empty($m['services_registered'])
            ) {
                $errors[] = 'Integrations-Extension-Modul (Blattknoten) darf keine Contracts bereitstellen '
                    . '(contracts_provided/resolvers_registered/services_registered)';
            }
            // Consumer-only (ch. 23.5.5): a leaf connector must not expose an entry
            // point — web_routes are an HTTP service surface and are forbidden.
            if (!empty($m['web_routes'])) {
                $errors[] = 'Integrations-Extension-Modul (Blattknoten) darf keine web_routes bereitstellen '
                    . '(Einstiegspunkt, Consumer-only)';
            }
        }

        foreach (self::SECTIONS as $section) {
            foreach ((array)($m[$section] ?? []) as $i => $entry) {
                $entry = (array)$entry;
                if (empty($entry['contract'])) {
                    $errors[] = "$section [$i]: 'contract' fehlt";
                }
                if (empty($entry['class'])) {
                    $errors[] = "$section [$i]: 'class' fehlt";
                } elseif ($ns !== '' && !str_starts_with((string)$entry['class'], rtrim($ns, '\\') . '\\')) {
                    $warnings[] = "$section [$i]: class '{$entry['class']}' liegt nicht im php_namespace '$ns'";
                }
            }
        }

        foreach ((array)($m['api_routes'] ?? []) as $i => $route) {
            $route = (array)$route;
            if (empty($route['method']) || !in_array(strtoupper((string)$route['method']), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $errors[] = "api_routes [$i]: ungültige/fehlende method";
            }
            $path = (string)($route['path'] ?? '');
            if ($path === '' || !str_starts_with($path, '/')) {
                $errors[] = "api_routes [$i]: 'path' muss mit '/' beginnen";
            } elseif (!preg_match('#^(/([A-Za-z0-9._~-]+|\{[a-z_][a-z0-9_]*\}))+/?$#', $path)) {
                // Only simple segments + {placeholder} — no regex metacharacters
                // (otherwise regex injection/ReDoS in the API router, P07).
                $errors[] = "api_routes [$i]: 'path' enthält unzulässige Zeichen (nur Segmente + {platzhalter})";
            }
            if (empty($route['class'])) {
                $errors[] = "api_routes [$i]: 'class' fehlt";
            } elseif ($ns !== '' && !str_starts_with((string)$route['class'], rtrim($ns, '\\') . '\\')) {
                $errors[] = "api_routes [$i]: class '{$route['class']}' liegt nicht im php_namespace '$ns'";
            }
            if (isset($route['auth']) && !in_array($route['auth'], ['user', 'public'], true)) {
                $errors[] = "api_routes [$i]: 'auth' ungültig (user|public)";
            }
        }

        foreach ((array)($m['web_routes'] ?? []) as $i => $route) {
            $route = (array)$route;
            $path = (string)($route['path'] ?? '');
            if ($path === '' || !str_starts_with($path, '/')) {
                $errors[] = "web_routes [$i]: 'path' muss mit '/' beginnen";
            } elseif (!preg_match('#^(/([A-Za-z0-9._~-]+|\{[a-z_][a-z0-9_]*\}))+/?$#', $path)) {
                // Only simple segments + {placeholder} — no regex metacharacters
                // (ReDoS-safe web router, ch. 23.16.3).
                $errors[] = "web_routes [$i]: 'path' enthält unzulässige Zeichen (nur Segmente + {platzhalter})";
            }
            if (empty($route['class'])) {
                $errors[] = "web_routes [$i]: 'class' fehlt";
            } elseif ($ns !== '' && !str_starts_with((string)$route['class'], rtrim($ns, '\\') . '\\')) {
                $errors[] = "web_routes [$i]: class '{$route['class']}' liegt nicht im php_namespace '$ns'";
            }
            if (empty($route['template'])) {
                $errors[] = "web_routes [$i]: 'template' fehlt";
            } elseif (!preg_match('#^[a-z0-9_]+(/[a-z0-9_]+)*$#', (string)$route['template'])) {
                // snake_case template name (CakePHP inflects via underscore();
                // mixed case breaks on case-sensitive filesystems).
                $warnings[] = "web_routes [$i]: 'template' sollte snake_case sein: {$route['template']}";
            }
            if (isset($route['auth']) && !in_array($route['auth'], ['user', 'guest'], true)) {
                $errors[] = "web_routes [$i]: 'auth' ungültig (user|guest)";
            }
            if (!empty($route['area']) && (string)($route['auth'] ?? 'user') !== 'user') {
                // An admin page (declares an `area`) must be authenticated: a guest
                // admin page would bypass login, the per-tenant module-enablement
                // gate and the area check in the web-mount dispatcher, yet render in
                // the admin shell. Reject it here; the dispatcher also hardens
                // against it (defense in depth).
                $errors[] = "web_routes [$i]: 'area' erfordert auth='user' (Admin-Seiten müssen authentifiziert sein)";
            }
            if (!empty($route['nav']) && empty($route['area'])) {
                $warnings[] = "web_routes [$i]: 'nav' ohne 'area' erscheint nicht in der Admin-Navigation";
            }
        }

        // Per-tenant config schema (Increment 5 Phase 3): each field needs a
        // snake_case key, an i18n label and a known input type; a select must ship
        // its options. The Core renders these into the tenant config form.
        $configTypes = ['bool', 'int', 'string', 'text', 'select'];
        foreach ((array)($m['config_schema'] ?? []) as $i => $field) {
            $field = (array)$field;
            if (empty($field['key']) || !preg_match('/^[a-z][a-z0-9_]*$/', (string)$field['key'])) {
                $errors[] = "config_schema [$i]: 'key' fehlt oder ungültig ([a-z][a-z0-9_]*)";
            }
            if (empty($field['label'])) {
                $errors[] = "config_schema [$i]: 'label' fehlt";
            }
            $type = (string)($field['type'] ?? '');
            if (!in_array($type, $configTypes, true)) {
                $errors[] = "config_schema [$i]: 'type' ungültig (bool|int|string|text|select)";
            }
            if ($type === 'select' && empty($field['options'])) {
                $errors[] = "config_schema [$i]: 'select' erfordert nicht-leere 'options'";
            }
        }

        // DB table exceptions (Inc 9): only module-GLOBAL tables need declaring; an
        // undeclared table is treated as tenant-scoped and must conform at install.
        foreach ((array)($m['tables'] ?? []) as $i => $table) {
            $table = (array)$table;
            if (empty($table['table']) || !preg_match('/^[a-z][a-z0-9_]*$/', (string)$table['table'])) {
                $errors[] = "tables [$i]: 'table' fehlt oder ungültig ([a-z][a-z0-9_]*)";
            }
            $scope = (string)($table['scope'] ?? '');
            if (!in_array($scope, ['tenant_scoped', 'global'], true)) {
                $errors[] = "tables [$i]: 'scope' ungültig (tenant_scoped|global)";
            }
            if ($scope === 'global' && empty($table['reason'])) {
                // A global table opts OUT of tenant isolation — make the intent auditable.
                $warnings[] = "tables [$i]: 'scope'=global ohne 'reason' (bitte begründen, warum modul-global)";
            }
        }

        foreach ((array)($m['contracts_provided'] ?? []) as $i => $contract) {
            $contract = (array)$contract;
            if (empty($contract['name'])) {
                $errors[] = "contracts_provided [$i]: 'name' fehlt";
            }
            if (empty($contract['type']) || !in_array($contract['type'], ['resolver', 'collector', 'service', 'event'], true)) {
                $errors[] = "contracts_provided [$i]: 'type' ungültig (resolver|collector|service|event)";
            }
            // Enhancing-not-gating (ch. 26.19, Decision 184): a provided resolver/
            // service contract must declare its absence semantics (error_behavior ->
            // the contract's default_behavior) so consumers have a defined neutral
            // state when no provider is active. collector/event types are additive by
            // nature (empty set / no-op) and need no declaration.
            if (in_array($contract['type'] ?? '', ['resolver', 'service'], true) && empty($contract['error_behavior'])) {
                $errors[] = "contracts_provided [$i]: 'error_behavior' fehlt "
                    . '(Resolver-/Service-Contract muss die Abwesenheits-/Default-Semantik deklarieren, Kap. 26.19)';
            }
        }

        // $errors/$warnings are append-only lists already — no array_values needed.
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @return array{errors:list<string>, warnings:list<string>}
     */
    public function lintFile(string $path): array
    {
        if (!is_file($path)) {
            return ['errors' => ["Manifest nicht gefunden: $path"], 'warnings' => []];
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            return ['errors' => ['Manifest ist kein gültiges JSON.'], 'warnings' => []];
        }

        return $this->lint($data);
    }

    /**
     * Static source check of a module's `src/` (Inc 8c): a module must NOT instantiate
     * the Core {@see \App\Service\Storage\StorageManager} (or {@see \App\Service\Storage\TenantStorage})
     * directly — those write to a caller-chosen path and let a module's per-tenant
     * files land OUTSIDE the `tenant/<id>/<key>/` convention, where the per-tenant
     * backup never captures them and the consumption meter never counts them. The
     * sanctioned handle is {@see \App\Service\Storage\ModuleStorage::for()}. This is the
     * author/CI fence complementing the runtime guard (Inc 8b): it catches the bug
     * before it ships and covers the few module entry points the runtime chokepoint
     * does not (e.g. an auth-provider resolved at bootstrap).
     *
     * @return array{errors:list<string>, warnings:list<string>}
     */
    public function lintSource(string $moduleDir): array
    {
        $srcDir = rtrim($moduleDir, '/\\') . DIRECTORY_SEPARATOR . 'src';
        if (!is_dir($srcDir)) {
            return ['errors' => [], 'warnings' => []];
        }
        $errors = [];
        // Matches `new StorageManager(` and `new \App\Service\Storage\TenantStorage(`,
        // i.e. a direct instantiation regardless of namespace qualification.
        $pattern = '/\bnew\s+\\\\?(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*(StorageManager|TenantStorage)\b/';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower((string)$file->getExtension()) !== 'php') {
                continue;
            }
            $code = (string)file_get_contents($file->getPathname());
            $rel = ltrim(str_replace($srcDir, '', $file->getPathname()), '/\\');
            if (preg_match($pattern, $code, $hit) === 1) {
                $errors[] = "src/$rel: direkte Storage-Instanziierung (new {$hit[1]}) verboten — "
                    . "nutze ModuleStorage::for('<modul-key>'), damit per-Tenant-Dateien unter "
                    . 'tenant/<id>/<key>/ liegen (sonst von Backup + Verbrauch nicht erfasst).';
            }
            foreach ($this->capabilityViolations($code) as $bad) {
                $errors[] = "src/$rel: verbotenes Primitiv '$bad' (Inc 10 Capability-Gate) — In-Process-Module "
                    . 'duerfen keine gefaehrlichen Primitive nutzen (Shell/eval/Reflection/eigene DB-Verbindung/'
                    . 'rohe Dateizugriffe). DB ueber den Modul-Db-Helfer (ConnectionManager::get), Dateien ueber '
                    . 'ModuleStorage. Sozket-/Stream-I/O ist erlaubt.';
            }
        }
        sort($errors);

        return ['errors' => $errors, 'warnings' => []];
    }

    /**
     * The capability violations across a module's `src/` (Inc 10), used by the install
     * gate to REFUSE a module that uses dangerous primitives — a security check distinct
     * from the storage-convention check in {@see self::lintSource()} (a module that
     * merely misuses StorageManager is not a sandbox-escape and is handled by the runtime
     * guard + module_lint, so it must NOT block install).
     *
     * @return list<string>
     */
    public function lintCapabilities(string $moduleDir): array
    {
        $srcDir = rtrim($moduleDir, '/\\') . DIRECTORY_SEPARATOR . 'src';
        if (!is_dir($srcDir)) {
            return [];
        }
        $errors = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower((string)$file->getExtension()) !== 'php') {
                continue;
            }
            $rel = ltrim(str_replace($srcDir, '', $file->getPathname()), '/\\');
            foreach ($this->capabilityViolations((string)file_get_contents($file->getPathname())) as $bad) {
                $errors[] = "src/$rel: $bad";
            }
        }
        sort($errors);

        return $errors;
    }

    /**
     * Forbidden global functions for an in-process module's src (Inc 10 capability
     * allowlist): shell/process execution, code eval, raw filesystem (use ModuleStorage),
     * raw DB connections (use ConnectionManager::get), and config/env mutation. Validated
     * against the real modules — none use these (legitimate socket/stream I/O like
     * fwrite/fgets/stream_socket_* stays ALLOWED, it is not a filesystem syscall).
     *
     * @var list<string>
     */
    private const FORBIDDEN_FUNCTIONS = [
        'eval', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec',
        'create_function', 'pg_connect', 'pg_pconnect',
        'fopen', 'file_get_contents', 'file_put_contents', 'readfile', 'mkdir', 'rmdir', 'unlink',
        'rename', 'copy', 'scandir', 'opendir', 'readdir', 'glob', 'chmod', 'chown',
        'extract', 'compact', 'putenv', 'ini_set', 'ini_alter', 'dl',
    ];

    /** @var list<string> Forbidden `new` classes: raw DB driver + reflection (visibility defeat). */
    private const FORBIDDEN_NEW = [
        'PDO', 'ReflectionClass', 'ReflectionMethod', 'ReflectionProperty', 'ReflectionFunction', 'ReflectionObject',
    ];

    /**
     * The forbidden capability primitives a single module source file uses (Inc 10).
     * Matches BARE global calls only — a leading `->`, `::`, `$`, `\` or `function `
     * excludes method calls/definitions, so `Db::exec()`, `->exec()` and a method named
     * `exec()` are NOT flagged; `ConnectionManager::get('default')` (the module Db helper)
     * and `preg_replace(.../u)` stay allowed.
     *
     * @return list<string>
     */
    private function capabilityViolations(string $code): array
    {
        $hits = [];
        foreach (self::FORBIDDEN_FUNCTIONS as $fn) {
            if (preg_match('/(?<![\w>:$\\\\])(?<!function )' . preg_quote($fn, '/') . '\s*\(/', $code) === 1) {
                $hits[] = $fn . '()';
            }
        }
        if (preg_match('/\bnew\s+\\\\?(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*(' . implode('|', self::FORBIDDEN_NEW) . ')\b/', $code, $m) === 1) {
            $hits[] = 'new ' . $m[1];
        }
        if (preg_match('/->\s*setAccessible\s*\(/', $code) === 1) {
            $hits[] = '->setAccessible()';
        }
        if (preg_match('/ConnectionManager::\s*(getConfig|setConfig|drop|alias)\s*\(/', $code) === 1) {
            $hits[] = 'ConnectionManager config mutation';
        }
        if (preg_match('/\$\$[A-Za-z_]/', $code) === 1) {
            $hits[] = 'variable-variables ($$)';
        }
        if (preg_match('/(?<![\w>:$\\\\])assert\s*\(\s*[\'"]/', $code) === 1) {
            $hits[] = 'assert(string)';
        }

        return $hits;
    }

    /**
     * Advisory author-time scan of a module's migrations/*.sql for the tenant
     * convention (Inc 9d). The AUTHORITATIVE check is the install gate
     * ({@see \App\Service\Module\ModuleLifecycle} — it inspects the LIVE catalog after
     * ALL migrations); this is only an early best-effort nudge over the raw SQL text,
     * hence WARNINGS not errors (modules add tenancy in retrofit migrations, which a
     * per-file scan cannot fully follow). It flags a created table that NEVER gets
     * `ENABLE ROW LEVEL SECURITY` across the whole migration set and is NOT declared
     * module-global in the manifest: either a forgotten tenant table (the gate will
     * refuse it) or a genuine global table that should be declared `scope: global`.
     *
     * @param list<string> $globalTables the manifest's declared module-global tables
     * @return array{errors:list<string>, warnings:list<string>}
     */
    public function lintMigrations(string $moduleDir, array $globalTables): array
    {
        $dir = rtrim($moduleDir, '/\\') . DIRECTORY_SEPARATOR . 'migrations';
        if (!is_dir($dir)) {
            return ['errors' => [], 'warnings' => []];
        }
        $sql = '';
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.sql') ?: [] as $file) {
            $sql .= "\n" . (string)file_get_contents($file);
        }
        // Tables created in the module schema (unqualified names).
        preg_match_all('/\bcreate\s+table\s+(?:if\s+not\s+exists\s+)?"?([a-z_][a-z0-9_]*)"?/i', $sql, $cm);
        $created = array_values(array_unique(array_map('strtolower', $cm[1])));
        // Tables built via the Core helper are conformant by construction.
        preg_match_all('/core\.create_tenant_table\s*\(\s*[^,]+,\s*\'([a-z_][a-z0-9_]*)\'/i', $sql, $hm);
        $helperBuilt = array_map('strtolower', $hm[1]);
        // Tables that get RLS enabled somewhere in the migration set.
        preg_match_all('/\balter\s+table\s+"?([a-z_][a-z0-9_]*)"?\s+enable\s+row\s+level\s+security/i', $sql, $em);
        $rlsEnabled = array_merge($helperBuilt, array_map('strtolower', $em[1]));
        $global = array_map('strtolower', $globalTables);

        $warnings = [];
        foreach ($created as $table) {
            if (in_array($table, $global, true) || in_array($table, $rlsEnabled, true)) {
                continue;
            }
            $warnings[] = "migrations: Tabelle '$table' erhält in keiner Migration RLS und ist nicht als "
                . 'tables[].scope=global deklariert — entweder tenant-konform machen (das Install-Gate prüft '
                . 'tenant_id + RLS + Policy + tenant-UNIQUE hart) oder im Manifest als global deklarieren.';
        }
        sort($warnings);

        return ['errors' => [], 'warnings' => $warnings];
    }

    /**
     * Author-time scan of a module's `locales/` PO catalogs for ICU placeholders
     * (`{0}`, `{1}`, …) in translations.
     *
     * The module i18n CONTRACT is sprintf (`%s`/`%d`), NOT ICU `{n}`: every module
     * translation domain is loaded with the sprintf formatter — {@see \App\I18n\StoreLocaleLoader}
     * builds each domain's `Package` as `new Package('sprintf', …)`, and a package's
     * own formatter always wins over the global default. An ICU numeric placeholder is
     * therefore never substituted: it renders RAW and the argument is dropped (e.g.
     * `{0} ist erforderlich.` instead of `Titel ist erforderlich.`). Such a bug is
     * invisible to a module test that happens to register the domain with the (ICU)
     * default formatter, so this static nudge catches it at author/CI time.
     *
     * WARNINGS not errors: a `{0}` could, rarely, be literal msgstr text rather than a
     * placeholder — flag it for review, don't hard-fail the lint.
     *
     * @return array{errors:list<string>, warnings:list<string>}
     */
    public function lintLocales(string $moduleDir): array
    {
        $dir = rtrim($moduleDir, '/\\') . DIRECTORY_SEPARATOR . 'locales';
        if (!is_dir($dir)) {
            return ['errors' => [], 'warnings' => []];
        }
        $warnings = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower((string)$file->getExtension()) !== 'po') {
                continue;
            }
            // Count only translation lines (msgstr) that carry an ICU numeric arg ref.
            $count = 0;
            foreach (file($file->getPathname()) ?: [] as $line) {
                if (str_starts_with($line, 'msgstr') && preg_match('/\{[0-9]+\}/', $line) === 1) {
                    $count++;
                }
            }
            if ($count > 0) {
                $rel = ltrim(str_replace($dir, '', $file->getPathname()), '/\\');
                $warnings[] = "locales/$rel: $count msgstr mit ICU-Platzhalter ({0}/{1}/…) — Modul-Domänen "
                    . 'werden mit dem sprintf-Formatter geladen (StoreLocaleLoader), nutze %s/%d statt ICU {n}, '
                    . 'sonst bleibt der Platzhalter im Render roh stehen (Argument geht verloren).';
            }
        }
        sort($warnings);

        return ['errors' => [], 'warnings' => $warnings];
    }
}
