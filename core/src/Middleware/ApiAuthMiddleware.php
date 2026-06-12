<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\Api\ApiRouteRegistry;
use App\Service\Api\TokenService;
use Authentication\Identity;
use Cake\Http\Response;
use Cake\ORM\Entity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Bearer-token authentication for the external API (ch. 29, Decision 162).
 *
 * Applies only to `/api/` paths. Reads `Authorization: Bearer <token>`, resolves
 * the user via `TokenService` and sets the identity (for RLS/audit) as well as the
 * token scopes as request attributes. Missing/invalid → JSON 401. Non-API requests
 * are passed through unchanged.
 */
class ApiAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, '/api/')) {
            return $handler->handle($request);
        }

        // A module API endpoint declared `public` requires no Core user token —
        // the module owns its own authentication (e.g. validating a queue-bound
        // module token from a header; ch. 29.x). It still passes through rate
        // limiting (per IP) and runs with no Core identity (anonymous RLS). All
        // other `/api/` paths require a valid Core Bearer token.
        if (
            str_starts_with($path, '/api/v1/m/')
            && (new ApiRouteRegistry())->authModeForRequest($request->getMethod(), $path) === 'public'
        ) {
            return $handler->handle($request);
        }

        $token = $this->bearer($request);
        if ($token === null) {
            return $this->unauthorized('missing_token', 'Authorization: Bearer <token> erforderlich.');
        }
        $auth = (new TokenService())->authenticate($token);
        if ($auth === null) {
            return $this->unauthorized('invalid_token', 'Token ungültig, abgelaufen oder widerrufen.');
        }

        $identity = new Identity(new Entity([
            'id' => $auth['user_id'],
            'username' => $auth['username'],
            'email' => $auth['email'],
            'locale' => $auth['locale'],
        ]));

        $request = $request
            ->withAttribute('identity', $identity)
            ->withAttribute('apiScopes', $auth['scopes'])
            ->withAttribute('apiTokenId', $auth['token_id']);

        return $handler->handle($request);
    }

    private function bearer(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header !== '' && preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function unauthorized(string $error, string $message): ResponseInterface
    {
        $body = (string)json_encode(['error' => $error, 'message' => $message]);

        return (new Response())
            ->withStatus(401)
            ->withType('application/json')
            ->withStringBody($body);
    }
}
