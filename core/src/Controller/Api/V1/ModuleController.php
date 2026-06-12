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
        if (!empty($route['scope']) && ($denied = $this->requireScope((string)$route['scope'])) !== null) {
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

        return $this->json(is_array($body) ? $body : ['data' => $body], $status);
    }
}
