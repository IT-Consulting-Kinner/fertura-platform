<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Model\ActorContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Copies the authenticated identity (if any) into the ActorContext so that the
 * FootprintBehavior can populate created_by/updated_by (Decision E8). Must run
 * AFTER the AuthenticationMiddleware.
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
