<?php
declare(strict_types=1);

namespace App\Service\Module;

use App\Service\Api\ApiRouteRegistry;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * Collects the **server-rendered web pages** declared by active modules
 * (ch. 23.16.3, web-mount) — the counterpart to {@see ApiRouteRegistry} for the
 * web layer (`/m/<key>/<path>`) instead of the JSON API (`/api/v1/m/<key>`).
 *
 * Source is the `web_routes` manifest section of the active modules. Each entry:
 * - `path`     path template, may contain `{param}` placeholders (e.g. `/tickets/{id}`)
 * - `class`    handler implementing {@see ModuleWebInterface}
 * - `template` template file (relative to the module's `templates/` dir, without `.php`)
 * - `area`     optional admin area key the page belongs to (gates access + nav)
 * - `auth`     `user` (default — login required) or `guest` (public page)
 * - `title`    optional page title (for layout/nav)
 *
 * Unlike API routes, web pages are matched **by path only** (not by HTTP method):
 * the same handler serves GET (render) and POST (form submit). Web pages are
 * **in-process only** — HTML rendering cannot cross the out-of-process RPC
 * boundary; modules declaring `web_routes` must run in_process (enforced at
 * install-time validation).
 */
class WebRouteRegistry
{
    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /**
     * All web pages declared by active modules.
     *
     * @return list<array{module_key:string,source_path:string,path:string,
     *     class:string,template:string,area:?string,auth:string,title:string}>
     */
    public function all(): array
    {
        $modules = $this->conn()->execute(
            "SELECT module_key, source_path FROM modules WHERE status = 'active'",
        )->fetchAll('assoc');

        $out = [];
        foreach ($modules as $m) {
            $sourcePath = rtrim((string)$m['source_path'], '/');
            $manifest = $sourcePath . '/manifest.json';
            if (!is_file($manifest)) {
                continue;
            }
            $data = json_decode((string)file_get_contents($manifest), true);
            foreach ((array)($data['web_routes'] ?? []) as $r) {
                if (empty($r['path']) || empty($r['class']) || empty($r['template'])) {
                    continue;
                }
                $auth = (string)($r['auth'] ?? 'user');
                $out[] = [
                    'module_key' => (string)$m['module_key'],
                    'source_path' => $sourcePath,
                    'path' => '/' . ltrim((string)$r['path'], '/'),
                    'class' => (string)$r['class'],
                    'template' => (string)$r['template'],
                    'area' => isset($r['area']) && $r['area'] !== '' ? (string)$r['area'] : null,
                    'auth' => $auth === 'guest' ? 'guest' : 'user',
                    'title' => (string)($r['title'] ?? ''),
                ];
            }
        }

        return $out;
    }

    /**
     * Finds the web page matching a module + path, including extracted path
     * parameters. Reuses the (ReDoS-safe) path matcher of the API registry.
     *
     * @return array{module_key:string,source_path:string,path:string,class:string,
     *     template:string,area:?string,auth:string,title:string,
     *     params:array<string,string>}|null
     */
    public function match(string $moduleKey, string $path): ?array
    {
        $path = '/' . ltrim($path, '/');
        foreach ($this->all() as $route) {
            if ($route['module_key'] !== $moduleKey) {
                continue;
            }
            $params = ApiRouteRegistry::matchPath($route['path'], $path);
            if ($params !== null) {
                return $route + ['params' => $params];
            }
        }

        return null;
    }
}
