<?php
declare(strict_types=1);

namespace App\Service\Sdk;

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
        if (isset($m['type']) && !in_array($m['type'], ['main', 'extension'], true)) {
            $warnings[] = "type unüblich (erwartet main|extension): {$m['type']}";
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
            if (!empty($route['nav']) && empty($route['area'])) {
                $warnings[] = "web_routes [$i]: 'nav' ohne 'area' erscheint nicht in der Admin-Navigation";
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
}
