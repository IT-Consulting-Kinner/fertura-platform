<?php
declare(strict_types=1);

namespace App\Service\Api;

use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;

/**
 * Collects the API endpoints declared by **active modules** (P07).
 *
 * The source is the `api_routes` manifest section of the active modules (the same
 * manifest-driven flow as autoloader/locales). Provides the route list (for
 * OpenAPI/dispatch) and a method+path → route matcher including path parameters.
 */
class ApiRouteRegistry
{
    private function conn(): ConnectionInterface
    {
        return ConnectionManager::get('default');
    }

    /**
     * All endpoints declared by active modules.
     *
     * @return list<array{module_key:string,isolation:string,method:string,path:string,class:string,summary:string,scope:?string}>
     */
    public function all(): array
    {
        $modules = $this->conn()->execute(
            "SELECT module_key, source_path, isolation FROM modules WHERE status = 'active'",
        )->fetchAll('assoc');

        $out = [];
        foreach ($modules as $m) {
            $manifest = rtrim((string)$m['source_path'], '/') . '/manifest.json';
            if (!is_file($manifest)) {
                continue;
            }
            $data = json_decode((string)file_get_contents($manifest), true);
            foreach ((array)($data['api_routes'] ?? []) as $r) {
                if (empty($r['method']) || empty($r['path']) || empty($r['class'])) {
                    continue;
                }
                $out[] = [
                    'module_key' => (string)$m['module_key'],
                    'isolation' => (string)($m['isolation'] ?: 'in_process'),
                    'method' => strtoupper((string)$r['method']),
                    'path' => '/' . ltrim((string)$r['path'], '/'),
                    'class' => (string)$r['class'],
                    'summary' => (string)($r['summary'] ?? ''),
                    'scope' => isset($r['scope']) ? (string)$r['scope'] : null,
                ];
            }
        }

        return $out;
    }

    /**
     * Finds the matching route for a module + method + path, including extracted
     * path parameters.
     *
     * @return array<string,mixed>|null
     */
    public function match(string $moduleKey, string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path = '/' . ltrim($path, '/');
        foreach ($this->all() as $route) {
            if ($route['module_key'] !== $moduleKey || $route['method'] !== $method) {
                continue;
            }
            $params = self::matchPath($route['path'], $path);
            if ($params !== null) {
                return $route + ['params' => $params];
            }
        }

        return null;
    }

    /**
     * Matches a path template (`/things/{id}`) against a concrete path and
     * returns the named parameters (or null if it does not match).
     *
     * @return array<string,string>|null
     */
    /** @var array<string,bool> Malformed templates already reported (log debouncing). */
    private static array $warnedTemplates = [];

    public static function matchPath(string $template, string $path): ?array
    {
        // Duplicate placeholder names (e.g. `/a/{id}/b/{id}`) would yield an invalid
        // PCRE (duplicate subpattern name) -> compile error + warning per request.
        // This is a manifest error in the module: treat it cleanly as a non-match
        // (no fatal/no warning) and log it once.
        if (preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $template, $names) && $names[1] !== []) {
            if (count($names[1]) !== count(array_unique($names[1]))) {
                if (!isset(self::$warnedTemplates[$template])) {
                    self::$warnedTemplates[$template] = true;
                    Log::warning("API-Route mit doppeltem Pfad-Parameter ignoriert: $template", ['component' => 'api']);
                }

                return null;
            }
        }

        // Security: first quote the entire template (no regex metacharacters from
        // the manifest -> no ReDoS/regex injection), THEN replace the now-escaped
        // placeholders `\{name\}` with named groups.
        $regex = (string)preg_replace(
            '/\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}/',
            '(?<$1>[^/]+)',
            preg_quote($template, '#'),
        );
        if (!preg_match('#^' . $regex . '$#', $path, $m)) {
            return null;
        }

        return array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}
