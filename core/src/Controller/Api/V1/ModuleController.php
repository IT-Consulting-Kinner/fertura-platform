<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Service\Api\ApiRouteRegistry;
use App\Service\Module\ContributionRuntime;
use App\Service\Module\TenantModuleService;
use Cake\Http\Response;
use Cake\Log\Log;
use Throwable;

/**
 * Dispatches module-registered API endpoints (P07): `/api/v1/m/<key>/<path>`.
 *
 * Looks up the route declared in the module manifest (`api_routes`) and invokes
 * the handler class via {@see ContributionRuntime} — in-process or
 * (out_of_process) via RPC in the isolated host (ch. 23.16.2). Auth/scopes
 * remain a core responsibility (Bearer token via middleware).
 */
class ModuleController extends ApiController
{
    public function dispatch(string $moduleKey, string $path = ''): Response
    {
        $method = strtoupper($this->request->getMethod());
        $route = (new ApiRouteRegistry())->match($moduleKey, $method, '/' . $path);
        if ($route === null) {
            return $this->json(['error' => 'not_found', 'message' => 'Kein passender Modul-Endpunkt.'], 404);
        }
        // `public` routes carry no Core scope check (the module owns its own auth,
        // Decision D1/D2); `user` routes enforce the declared scope.
        $isPublic = ($route['auth'] ?? 'user') === 'public';
        // Fail-closed per-tenant module enablement (operator/tenant authz §5): an
        // authenticated module API endpoint is only reachable when the module is
        // enabled for the caller's tenant. `public` routes are exempt — they carry
        // no Core auth and run with no tenant context. Checked before the scope
        // gate so a 404 (not 403) uniformly hides a module not provisioned to this
        // tenant, mirroring the web-mount dispatcher.
        if (!$isPublic && !(new TenantModuleService())->isEnabled($moduleKey)) {
            return $this->json(['error' => 'not_found', 'message' => 'Kein passender Modul-Endpunkt.'], 404);
        }
        if (!$isPublic && !empty($route['scope']) && ($denied = $this->requireScope((string)$route['scope'])) !== null) {
            return $denied;
        }

        $request = [
            'method' => $method,
            'path' => '/' . $path,
            'params' => $route['params'],
            'query' => $this->request->getQueryParams(),
            'body' => $this->request->getParsedBody() ?? [],
            'user_id' => $this->userId(),
            'scopes' => $this->scopes(),
            // Header lines (name => value) so a `public`-route module can validate
            // its OWN token (e.g. a queue-bound module token).
            'headers' => array_map(
                static fn(array $v): string => implode(', ', $v),
                $this->request->getHeaders(),
            ),
        ];
        // Per-tenant module config (Increment 5 Phase 3); empty for public routes
        // (no tenant context) — the module reads it via $request['module_config'].
        $request['module_config'] = (new TenantModuleService())->config($moduleKey);

        try {
            $result = (array)(new ContributionRuntime())->call(
                ['class' => $route['class'], 'module_key' => $moduleKey, 'isolation' => $route['isolation']],
                'handle',
                [$request],
            );
        } catch (Throwable $e) {
            // Do not leak internal details (paths/SQL/classes) to the client.
            Log::error('Modul-Endpunkt-Fehler: ' . $e->getMessage(), [
                'module' => $moduleKey,
                'path' => '/' . $path,
            ]);

            return $this->json(['error' => 'module_error', 'message' => 'Modul-Endpunkt fehlgeschlagen.'], 502);
        }

        $status = (int)($result['status'] ?? 200);
        $body = array_key_exists('body', $result) ? $result['body'] : $result;
        $response = $this->json(is_array($body) ? $body : ['data' => $body], $status);

        // A `public` route may make its content cacheable for the headless content
        // API (E160) and signal backoff via Retry-After for its own rate-limiting
        // (E175): the module owns these directives. Only allowlisted response
        // headers pass through — never Set-Cookie or security headers. `user`
        // routes do not (avoid caching token-scoped data in shared caches).
        if ($isPublic && isset($result['headers']) && is_array($result['headers'])) {
            $response = $this->applyPassthroughHeaders($response, $result['headers']);
        }

        return $response;
    }

    /**
     * Response headers a `public` module route may set: caching directives (E160)
     * plus `Retry-After`, so a module can signal 429/503 backoff for its own
     * rate-limiting (E175). Never `Set-Cookie` or security headers.
     */
    private const PASSTHROUGH_HEADERS = [
        'cache-control', 'etag', 'last-modified', 'expires', 'vary', 'age', 'retry-after',
    ];

    /**
     * Copies only the allowlisted response headers from the module result onto the
     * response, normalizing the header name.
     *
     * @param array<mixed> $headers
     */
    private function applyPassthroughHeaders(Response $response, array $headers): Response
    {
        foreach ($headers as $name => $value) {
            if (
                in_array(strtolower((string)$name), self::PASSTHROUGH_HEADERS, true)
                && (is_string($value) || is_int($value))
            ) {
                $response = $response->withHeader((string)$name, (string)$value);
            }
        }

        return $response;
    }
}
