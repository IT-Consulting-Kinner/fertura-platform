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
            if (preg_match($pattern, $code, $hit) === 1) {
                $rel = ltrim(str_replace($srcDir, '', $file->getPathname()), '/\\');
                $errors[] = "src/$rel: direkte Storage-Instanziierung (new {$hit[1]}) verboten — "
                    . "nutze ModuleStorage::for('<modul-key>'), damit per-Tenant-Dateien unter "
                    . 'tenant/<id>/<key>/ liegen (sonst von Backup + Verbrauch nicht erfasst).';
            }
        }
        sort($errors);

        return ['errors' => $errors, 'warnings' => []];
    }
}
