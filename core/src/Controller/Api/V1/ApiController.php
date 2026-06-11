<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Service\Api\TokenService;
use Cake\Controller\Controller;
use Cake\Http\Response;

/**
 * Base for the external API v1 (ch. 29). Pure JSON controllers with no session/
 * flash/Authentication component — identity and scopes come from the
 * `ApiAuthMiddleware` (Bearer token). Authorization: token scope plus (via RLS/
 * permissions) the rights of the bound user.
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

    /** Returns a 403 response if the required scope is missing, otherwise null. */
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
