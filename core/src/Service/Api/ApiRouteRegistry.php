<?php
declare(strict_types=1);

namespace App\Service\Api;

use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;

/**
 * Sammelt die von **aktiven Modulen** deklarierten API-Endpunkte (P07).
 *
 * Quelle ist die Manifest-Sektion `api_routes` der aktiven Module (gleiche
 * Manifest-getriebene Strömung wie Autoloader/Locales). Liefert die Routenliste
 * (für OpenAPI/Dispatch) und ein Matching method+path → Route inkl. Pfad-
 * Parametern.
 */
class ApiRouteRegistry
{
    private function conn(): ConnectionInterface
    {
        return ConnectionManager::get('default');
    }

    /**
     * Alle von aktiven Modulen deklarierten Endpunkte.
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
     * Findet die passende Route für ein Modul + Methode + Pfad inkl. extrahierter
     * Pfad-Parameter.
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
     * Matcht ein Pfad-Template (`/things/{id}`) gegen einen konkreten Pfad und
     * gibt die benannten Parameter zurück (oder null bei Nichttreffer).
     *
     * @return array<string,string>|null
     */
    /** @var array<string,bool> Bereits gemeldete fehlerhafte Templates (Log-Entprellung). */
    private static array $warnedTemplates = [];

    public static function matchPath(string $template, string $path): ?array
    {
        // Doppelte Platzhalternamen (z. B. `/a/{id}/b/{id}`) ergäben ein ungültiges
        // PCRE (duplicate subpattern name) -> Compile-Fehler + Warnung pro Request.
        // Das ist ein Manifest-Fehler des Moduls: sauber als Nichttreffer behandeln
        // (kein Fatal/keine Warnung) und einmalig protokollieren.
        if (preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $template, $names) && $names[1] !== []) {
            if (count($names[1]) !== count(array_unique($names[1]))) {
                if (!isset(self::$warnedTemplates[$template])) {
                    self::$warnedTemplates[$template] = true;
                    Log::warning("API-Route mit doppeltem Pfad-Parameter ignoriert: $template", ['component' => 'api']);
                }

                return null;
            }
        }

        // Sicherheit: erst das gesamte Template quoten (keine Regex-Metazeichen
        // aus dem Manifest -> kein ReDoS/Regex-Injection), DANN die nun escapten
        // Platzhalter `\{name\}` durch benannte Gruppen ersetzen.
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
