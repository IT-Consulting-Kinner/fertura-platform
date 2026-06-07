<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Log\LogContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Befüllt den prozessweiten {@see LogContext} pro Request (Kap. 20.2.3):
 * `correlation_id` (aus `X-Correlation-Id` übernommen oder neu), `request_id`
 * und `component=core`. Outermost platziert, damit auch von der
 * ErrorHandler-Middleware geloggte Ausnahmen den Kontext tragen.
 */
class LogContextMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $correlation = $request->getHeaderLine('X-Correlation-Id');
        if ($correlation === '') {
            $correlation = Uuid::v7()->toRfc4122();
        }
        LogContext::set([
            'correlation_id' => $correlation,
            'request_id' => Uuid::v7()->toRfc4122(),
            'component' => 'core',
        ]);
        try {
            return $handler->handle($request);
        } finally {
            LogContext::clear();
        }
    }
}
