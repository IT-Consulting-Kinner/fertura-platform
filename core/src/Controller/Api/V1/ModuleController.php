<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Service\Api\ApiRouteRegistry;
use App\Service\Module\ContributionRuntime;
use Cake\Http\Response;
use Throwable;

/**
 * Dispatcht modul-registrierte API-Endpunkte (P07): `/api/v1/m/<key>/<pfad>`.
 *
 * Findet die im Modul-Manifest deklarierte Route (`api_routes`) und ruft die
 * Handler-Klasse über {@see ContributionRuntime} auf — in-process oder
 * (out_of_process) über RPC im isolierten Host (Kap. 23.16.2). Auth/Scopes
 * bleiben Core-Sache (Bearer-Token via Middleware).
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
            // Interne Details (Pfade/SQL/Klassen) nicht an den Client geben.
            \Cake\Log\Log::error('Modul-Endpunkt-Fehler: ' . $e->getMessage(), [
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
