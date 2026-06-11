<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\Cache\CacheStore;
use App\Service\Settings\SettingsManager;
use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rate limiting for the external API (Program Tier-1, P07).
 *
 * Fixed window per minute, keyed per **API token** (or client IP when
 * unauthenticated) via the cache (P02; Redis recommended for atomic INCR in
 * multi-instance deployments). Responds with `429` + `Retry-After` when the limit
 * is exceeded and sets `X-RateLimit-Limit/-Remaining/-Reset` on every response.
 * Cache unavailable → fail-open (availability over strict enforcement).
 */
class ApiRateLimitMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!str_starts_with($request->getUri()->getPath(), '/api/')) {
            return $handler->handle($request);
        }

        $settings = new SettingsManager();
        if (!(bool)$settings->get('core', 'api.rate_limit.enabled', true)) {
            return $handler->handle($request);
        }
        $limit = (int)$settings->get('core', 'api.rate_limit.per_minute', 120);

        $id = (string)($request->getAttribute('apiTokenId')
            ?: 'ip:' . (string)($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown'));
        $bucket = (int)floor(time() / 60);
        $reset = ($bucket + 1) * 60;

        $count = (new CacheStore('_app_ratelimit_'))->increment("rl:$bucket:$id", 1);

        if ($count > $limit) {
            return (new Response())
                ->withStatus(429)
                ->withType('application/json')
                ->withHeader('Retry-After', (string)max(1, $reset - time()))
                ->withHeader('X-RateLimit-Limit', (string)$limit)
                ->withHeader('X-RateLimit-Remaining', '0')
                ->withHeader('X-RateLimit-Reset', (string)$reset)
                ->withStringBody((string)json_encode([
                    'error' => 'rate_limited',
                    'message' => 'Zu viele Anfragen — bitte später erneut versuchen.',
                ]));
        }

        return $handler->handle($request)
            ->withHeader('X-RateLimit-Limit', (string)$limit)
            ->withHeader('X-RateLimit-Remaining', (string)max(0, $limit - $count))
            ->withHeader('X-RateLimit-Reset', (string)$reset);
    }
}
