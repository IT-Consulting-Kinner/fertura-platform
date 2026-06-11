<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Log\LogContext;
use App\Log\Trace;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Populates the process-wide {@see LogContext} per request (ch. 20.2.3):
 * `correlation_id` (taken from `X-Correlation-Id` or newly generated), `request_id`,
 * `component=core` and the W3C trace (`trace_id`/`span_id`, continued from
 * `traceparent` or newly generated). Placed outermost so that exceptions logged by
 * the ErrorHandler middleware also carry the context.
 */
class LogContextMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $correlation = $request->getHeaderLine('X-Correlation-Id');
        if ($correlation === '') {
            $correlation = Uuid::v7()->toRfc4122();
        }
        $tp = Trace::parseTraceparent($request->getHeaderLine('traceparent'));
        LogContext::set([
            'correlation_id' => $correlation,
            'request_id' => Uuid::v7()->toRfc4122(),
            'component' => 'core',
            'trace_id' => $tp['trace_id'] ?? Trace::newTraceId(),
            'span_id' => Trace::newSpanId(),
        ]);
        try {
            return $handler->handle($request);
        } finally {
            LogContext::clear();
        }
    }
}
