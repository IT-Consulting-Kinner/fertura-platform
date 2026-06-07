<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Service\Api\TokenService;
use Cake\Controller\Controller;
use Cake\Http\Response;

/**
 * Basis für die externe API v1 (Kap. 29). Reine JSON-Controller ohne Session/
 * Flash/Authentication-Component — die Identität + Scopes stammen aus der
 * `ApiAuthMiddleware` (Bearer-Token). Autorisierung: Token-Scope + (über RLS/
 * Permissions) die Rechte des gebundenen Benutzers.
 */
class ApiController extends Controller
{
    /** @return list<string> */
    protected function scopes(): array
    {
        return (array)$this->request->getAttribute('apiScopes', []);
    }

    protected function userId(): string
    {
        $identity = $this->request->getAttribute('identity');

        return $identity !== null ? (string)$identity->getIdentifier() : '';
    }

    /** Liefert eine 403-Antwort, wenn der geforderte Scope fehlt, sonst null. */
    protected function requireScope(string $scope): ?Response
    {
        if (!TokenService::hasScope($this->scopes(), $scope)) {
            return $this->json(['error' => 'insufficient_scope', 'required' => $scope], 403);
        }

        return null;
    }

    /** @param array<string,mixed> $data */
    protected function json(array $data, int $status = 200): Response
    {
        return $this->response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody((string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
