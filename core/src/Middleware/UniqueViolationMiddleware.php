<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Error\UniqueViolation;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Log\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Global safety net: turns a PostgreSQL unique-constraint violation (SQLSTATE
 * 23505) that bubbles out of any action — creating OR renaming an element to a
 * name/value that already exists — into a user WARNING instead of a 500. A
 * browser request gets a Flash warning + redirect back to the form; an API/JSON
 * request gets a 409 with the message. Anything that is not a 23505 is re-thrown
 * unchanged for the normal error handler.
 *
 * Placement: this MUST sit directly OUTSIDE {@see TransactionRlsMiddleware} — that
 * layer opens the request transaction and, on any throwable, rolls it back and
 * re-throws, so by the time we catch here the connection is clean again and we
 * can safely emit a response. (A write site may still pre-check for a nicer,
 * field-specific message + form re-render, e.g. {@see \App\Controller\Admin\GroupsController::add};
 * this net only guarantees no unhandled violation ever reaches the user as a 500.)
 */
class UniqueViolationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $e) {
            $constraint = UniqueViolation::constraintOf($e);
            if ($constraint === null) {
                throw $e;
            }
            // WARNING (not info) + the driver message: a 23505 is usually a user
            // duplicate, but it can also be a genuine bug (a code path that re-inserts
            // an existing row). Keep it discoverable to ops with the constraint + cause
            // rather than silently downgrading every case to an invisible info line.
            $name = $constraint !== '' ? $constraint : '(unbenannt)';
            $detail = 'Unique-Verletzung abgefangen (' . $name . '): ' . $e->getMessage();
            Log::warning($detail, ['scope' => ['uniqueViolation']]);
            $message = UniqueViolation::messageFor($constraint);

            if ($this->expectsJson($request)) {
                return (new Response())
                    ->withStatus(409)
                    ->withType('application/json')
                    ->withStringBody((string)json_encode(['error' => $message]));
            }

            if ($request instanceof ServerRequest) {
                $request->getFlash()->warning($message);
            }

            return (new Response())->withStatus(303)->withLocation($this->backTo($request));
        }
    }

    /** An API/JSON caller gets a 409 body; a browser gets flash + redirect. */
    private function expectsJson(ServerRequestInterface $request): bool
    {
        if (str_contains(strtolower($request->getHeaderLine('Accept')), 'json')) {
            return true;
        }
        $path = $request->getUri()->getPath();

        return str_starts_with($path, '/api') || str_starts_with($path, '/scim');
    }

    /** Same-origin Referer to send the browser back to, else the site root (no open redirect). */
    private function backTo(ServerRequestInterface $request): string
    {
        $referer = $request->getHeaderLine('Referer');
        if ($referer !== '') {
            $host = parse_url($referer, PHP_URL_HOST);
            if ($host !== false && $host === $request->getUri()->getHost()) {
                return $referer;
            }
        }

        return '/';
    }
}
