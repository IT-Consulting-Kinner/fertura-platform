<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\Api\ApiRouteRegistry;
use Cake\Core\Configure;
use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CORS for the headless content API (E160).
 *
 * Lets a tenant site consume the **public** module API (`/api/v1/m/<key>/…` with
 * `auth: public`) cross-origin from the browser. CORS headers are added ONLY for
 * public module routes and ONLY for origins on the operator-configured allowlist
 * (`CORS_ALLOWED_ORIGINS`, comma-separated, or `*`; default empty = CORS off,
 * security-conservative). `user` (token) routes never get CORS, and no
 * `Allow-Credentials` is sent (the public content is token-less).
 *
 * Runs before the routing middleware so a preflight `OPTIONS` (which matches no
 * route) is answered directly instead of 404.
 */
class CorsMiddleware implements MiddlewareInterface
{
    private const ALLOW_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
    private const ALLOW_HEADERS = 'Content-Type, Authorization, X-Requested-With, X-Module-Token';
    private const MAX_AGE = '600';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, '/api/v1/m/')) {
            return $handler->handle($request);
        }

        $origin = $request->getHeaderLine('Origin');
        $allowedOrigin = $origin !== '' ? $this->matchOrigin($origin) : null;
        $method = strtoupper($request->getMethod());

        if ($method === 'OPTIONS') {
            // Preflight: the real method is announced in Access-Control-Request-Method.
            $reqMethod = strtoupper($request->getHeaderLine('Access-Control-Request-Method')) ?: 'GET';
            if ($allowedOrigin !== null && $this->isPublic($reqMethod, $path)) {
                return $this->withCors(new Response(['status' => 204]), $allowedOrigin, $request);
            }

            return $handler->handle($request);
        }

        $response = $handler->handle($request);
        if ($allowedOrigin !== null && $this->isPublic($method, $path)) {
            $response = $this->withCors($response, $allowedOrigin, $request);
        }

        return $response;
    }

    private function isPublic(string $method, string $apiPath): bool
    {
        return (new ApiRouteRegistry())->authModeForRequest($method, $apiPath) === 'public';
    }

    /** Resolves the Allow-Origin value for an incoming Origin, or null if not allowed. */
    private function matchOrigin(string $origin): ?string
    {
        $raw = trim((string)(Configure::read('Cors.allowedOrigins') ?? env('CORS_ALLOWED_ORIGINS', '')));
        if ($raw === '') {
            return null;
        }
        if ($raw === '*') {
            return '*';
        }
        $list = array_map('trim', explode(',', $raw));

        return in_array($origin, $list, true) ? $origin : null;
    }

    private function withCors(
        ResponseInterface $response,
        string $allowedOrigin,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $reqHeaders = $request->getHeaderLine('Access-Control-Request-Headers');
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
            ->withHeader('Access-Control-Allow-Methods', self::ALLOW_METHODS)
            ->withHeader('Access-Control-Allow-Headers', $reqHeaders !== '' ? $reqHeaders : self::ALLOW_HEADERS)
            ->withHeader('Access-Control-Max-Age', self::MAX_AGE);
        if ($allowedOrigin !== '*') {
            // Cache key must vary by Origin when the allow-list echoes the origin.
            $response = $response->withAddedHeader('Vary', 'Origin');
        }

        return $response;
    }
}
