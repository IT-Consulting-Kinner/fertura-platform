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
 * Per-IP-Anmeldeschutz **vor** der eigentlichen Authentifizierung (Peer-Review #2,
 * E99). Die `AuthenticationMiddleware` prüft die Zugangsdaten (Argon2/bcrypt =
 * CPU) bei jedem POST; eine reine Per-Benutzer-Sperre im Controller greift erst
 * danach und bremst weder **Password-Spraying** über viele Benutzernamen noch die
 * Pre-Auth-CPU-Last.
 *
 * Diese Middleware blockt eine IP, die im Zeitfenster zu viele Fehlversuche (über
 * beliebige Konten) hatte, mit `429` — bevor überhaupt ein Passwort gehasht wird.
 * Die feinkörnige, UX-freundliche Per-Benutzer-Sperre bleibt im `AuthController`.
 *
 * Sie schlüsselt bewusst auf `clientIp()` (= `REMOTE_ADDR`, solange keine Trusted
 * Proxies konfiguriert sind) und ist damit nicht per `X-Forwarded-For` fälschbar.
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
                // Throttle-Speicher (DB) nicht verfügbar -> Anmeldung nicht blockieren
                // (Verfügbarkeit vor strikter Grenze; der Controller bleibt zuständig).
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
