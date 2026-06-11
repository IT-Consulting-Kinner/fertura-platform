<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Auth\LoginThrottle;
use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Per-IP login protection **before** the actual authentication (Peer-Review #2,
 * E99). The `AuthenticationMiddleware` verifies the credentials (Argon2/bcrypt =
 * CPU) on every POST; a purely per-user lockout in the controller only takes effect
 * afterwards and throttles neither **password spraying** across many usernames nor
 * the pre-auth CPU load.
 *
 * This middleware blocks an IP that has had too many failed attempts (across any
 * accounts) within the time window with `429` — before any password is even hashed.
 * The fine-grained, UX-friendly per-user lockout stays in the `AuthController`.
 *
 * It deliberately keys on `clientIp()` (= `REMOTE_ADDR` as long as no trusted
 * proxies are configured) and is therefore not spoofable via `X-Forwarded-For`.
 */
class LoginThrottleMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isLoginPost($request)) {
            try {
                /** @var \Cake\Http\ServerRequest $request */
                $ip = (string)$request->clientIp();
                if ($ip !== '' && (new LoginThrottle())->isIpBlocked($ip)) {
                    return (new Response())
                        ->withStatus(429)
                        ->withType('text/plain')
                        ->withHeader('Retry-After', '900')
                        ->withStringBody("Zu viele Anmeldeversuche. Bitte später erneut versuchen.\n");
                }
            } catch (Throwable) {
                // Throttle store (DB) unavailable -> do not block the login
                // (availability over strict enforcement; the controller remains responsible).
            }
        }

        return $handler->handle($request);
    }

    private function isLoginPost(ServerRequestInterface $request): bool
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return false;
        }
        $path = rtrim($request->getUri()->getPath(), '/');

        return $path === '/login';
    }
}
