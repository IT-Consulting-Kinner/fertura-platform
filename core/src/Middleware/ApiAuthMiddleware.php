<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\Api\TokenService;
use Authentication\Identity;
use Cake\Http\Response;
use Cake\ORM\Entity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Bearer-Token-Authentifizierung für die externe API (Kap. 29, Entscheidung 162).
 *
 * Greift nur auf `/api/`-Pfade. Liest `Authorization: Bearer <token>`, löst den
 * Benutzer über den `TokenService` auf und setzt die Identität (für RLS/Audit)
 * sowie die Token-Scopes als Request-Attribute. Fehlt/ungültig → JSON 401.
 * Nicht-API-Requests werden unverändert durchgereicht.
 */
class ApiAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, '/api/')) {
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
