<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Service\Api\ApiRouteRegistry;
use App\Service\Module\ContributionRuntime;
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
        // API (E160): the module knows when content changes, so it owns the
        // caching directives. Only allowlisted response headers pass through —
        // never Set-Cookie or security headers. `user` routes do not (avoid
        // caching token-scoped data in shared caches).
        if ($isPublic && isset($result['headers']) && is_array($result['headers'])) {
            $response = $this->applyCacheHeaders($response, $result['headers']);
        }

        return $response;
    }

    /** Response headers a `public` module route may set (caching only). */
    private const CACHEABLE_HEADERS = ['cache-control', 'etag', 'last-modified', 'expires', 'vary', 'age'];

    /**
     * Copies only the allowlisted (caching) headers from the module result onto
     * the response, normalizing the header name.
     *
     * @param array<mixed> $headers
     */
    private function applyCacheHeaders(Response $response, array $headers): Response
    {
        foreach ($headers as $name => $value) {
            if (
                in_array(strtolower((string)$name), self::CACHEABLE_HEADERS, true)
                && (is_string($value) || is_int($value))
            ) {
                $response = $response->withHeader((string)$name, (string)$value);
            }
        }

        return $response;
    }
}
