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
 * - `area`     optional admin area key. If set, the page is an **admin page**:
 *              rendered in the Core admin shell (admin layout + sidebar), gated on
 *              the user holding that area. If unset, the page renders standalone in
 *              the Core `module` layout (an end-user/guest page).
 * - `auth`     `user` (default — login required) or `guest` (public page)
 * - `title`    optional page title (for the layout)
 * - `nav`      optional i18n key — when set on an admin page, the page appears as a
 *              sidebar item in the admin navigation
 * - `nav_group` optional i18n key — the sidebar group heading for a module-defined
 *              admin area (defaults to the area key)
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
     *     class:string,template:string,area:?string,auth:string,title:string,
     *     nav:string,nav_group:string}>
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
                    'nav' => (string)($r['nav'] ?? ''),
                    'nav_group' => (string)($r['nav_group'] ?? ''),
                ];
            }
        }

        return $out;
    }

    /**
     * Admin sidebar/menu entries contributed by active modules, grouped by admin
     * area (only admin pages — `area` set — that declare a `nav` label). The shape
     * mirrors {@see \App\Controller\Admin\AdminController::NAV} so the two can be
     * merged: `area => ['label' => text, 'items' => [[text, url], …]]`.
     *
     * Labels are resolved HERE against the contributing module's i18n **domain**
     * (the module key), because the Core renders nav labels with the default-domain
     * `__()` and there is no default->module fallback — a module-domain key would
     * otherwise show up raw. A module may thus use proper `<key>` labels (resolved
     * in its own domain) or plain literals (passed through unchanged). Core areas
     * keep their `admin.*` default-domain keys (resolved by the templates' `__()`,
     * which is a harmless no-op on the already-resolved module strings).
     *
     * @return array<string, array{label:string, items:list<array{0:string,1:string}>}>
     */
    public function adminNav(): array
    {
        // Fail-closed per-tenant gate (operator/tenant authz §5): a module's
        // admin-nav entries only appear for tenants that have the module enabled —
        // the same allow-list the dispatch gate enforces, so the sidebar never
        // links to a page this tenant would get a 404 on.
        $enabled = (new TenantModuleService())->enabledKeys();
        $out = [];
        foreach ($this->all() as $r) {
            if ($r['area'] === null || $r['nav'] === '' || !in_array($r['module_key'], $enabled, true)) {
                continue;
            }
            $area = $r['area'];
            $domain = $r['module_key'];
            if (!isset($out[$area])) {
                $label = $r['nav_group'] !== '' ? (string)__d($domain, $r['nav_group']) : $area;
                $out[$area] = ['label' => $label, 'items' => []];
            }
            $out[$area]['items'][] = [(string)__d($domain, $r['nav']), '/m/' . $r['module_key'] . $r['path']];
        }

        return $out;
    }

    /**
     * Finds the web page matching a module + path, including extracted path
     * parameters. Reuses the (ReDoS-safe) path matcher of the API registry.
     *
     * @return array{module_key:string,source_path:string,path:string,class:string,
     *     template:string,area:?string,auth:string,title:string,nav:string,
     *     nav_group:string,params:array<string,string>}|null
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
