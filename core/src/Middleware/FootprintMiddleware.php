<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Model\ActorContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Übernimmt die angemeldete Identität (sofern vorhanden) in den ActorContext,
 * damit das FootprintBehavior created_by/updated_by setzen kann (Entscheidung E8).
 * Muss NACH der AuthenticationMiddleware laufen.
 */
class FootprintMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identity = $request->getAttribute('identity');
        if ($identity !== null) {
            ActorContext::set((string)$identity->getIdentifier());
        }

        try {
            return $handler->handle($request);
        } finally {
            ActorContext::clear();
        }
    }
}
