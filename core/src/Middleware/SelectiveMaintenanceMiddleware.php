<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\System\AllowTokenCookie;
use App\Service\System\MaintenanceService;
use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Selective maintenance gate — Phase 3 (docs/maintenance-mode-design.md §4.3).
 *
 * When a maintenance session is engaged, every request is rejected with 503 EXCEPT
 * the operator who engaged it — identified either by their authenticated identity
 * matching `actor_user_id`, or by presenting the server-issued allow-token cookie
 * whose hash matches the stored one. This is the post-auth mass-logout (decision #2):
 * other users keep their session but every request 503s, and crucially POST /login /
 * SSO / MFA also 503 (they arrive with no operator identity and no allow-token), so
 * nobody can sign in to bypass the window.
 *
 * Runs AFTER {@see AuthenticationMiddleware} (so the identity attribute is populated)
 * and BEFORE the controller. The maintenance state is read UNCACHED via
 * {@see MaintenanceService} — never the settings cache, whose lag would let other
 * instances ignore the window. The LB liveness/readiness probes are allow-listed so
 * the load balancer keeps routing to this node (the operator reaches it through the
 * LB); internal /health/detail + /metrics stay behind the gate.
 *
 * This complements the older {@see MaintenanceMiddleware} (file-flag/DB-setting 503),
 * which blocks EVERYONE unconditionally and stays reserved for the restore cutover.
 */
class SelectiveMaintenanceMiddleware implements MiddlewareInterface
{
    /**
     * The load-balancer liveness/readiness probes, EXACT-matched so they keep
     * working through a maintenance window (the operator reaches the node via the
     * LB). Deliberately NOT /health/detail or /metrics — those carry internal state
     * and must stay behind the gate — and deliberately not a prefix match, so a path
     * like /healthcheck-evil cannot slip the gate.
     */
    private const ALLOW_PATHS = ['/health', '/health/ready'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Allow-list first, before the maintenance read: the LB probes must answer
        // even if the maintenance state cannot be determined (fail-closed below).
        $path = $request->getUri()->getPath();
        if (in_array($path, self::ALLOW_PATHS, true)) {
            return $handler->handle($request);
        }

        try {
            $session = (new MaintenanceService())->activeSession();
        } catch (Throwable) {
            // Fail closed: if the state cannot be determined we assume maintenance.
            // The request path needs the DB anyway, so this is no availability
            // regression — a DB outage breaks the request regardless.
            return $this->blocked($request);
        }

        if ($session === null) {
            return $handler->handle($request); // not in maintenance
        }

        if ($this->isOperator($request, $session)) {
            return $handler->handle($request);
        }

        return $this->blocked($request);
    }

    /**
     * The operator passes either by being the authenticated activator (actor
     * fallback) or by presenting the matching allow-token cookie.
     *
     * @param array<string,mixed> $session
     */
    private function isOperator(ServerRequestInterface $request, array $session): bool
    {
        // (a) The activator's own authenticated identity.
        $identity = $request->getAttribute('identity');
        $actorId = $session['actor_user_id'] ?? null;
        if ($identity !== null && is_string($actorId) && $actorId !== '') {
            $id = $identity->getIdentifier();
            if (is_string($id) && hash_equals($actorId, $id)) {
                return true;
            }
        }

        // (b) The server-issued allow-token cookie (constant-time hash compare).
        $cookies = $request->getCookieParams();
        $token = $cookies[AllowTokenCookie::NAME] ?? null;
        $stored = (string)($session['allow_token_hash'] ?? '');
        if (is_string($token) && $token !== '' && $stored !== '') {
            return hash_equals($stored, hash('sha256', $token));
        }

        return false;
    }

    private function blocked(ServerRequestInterface $request): ResponseInterface
    {
        $wantsJson = str_starts_with($request->getUri()->getPath(), '/api/')
            || str_contains($request->getHeaderLine('Accept'), 'application/json');

        if ($wantsJson) {
            return (new Response())
                ->withStatus(503)
                ->withType('application/json')
                ->withStringBody('{"error":"maintenance"}');
        }

        return (new Response())
            ->withStatus(503)
            ->withType('text/plain')
            ->withStringBody("Wartungsmodus aktiv. Bitte später erneut versuchen.\n");
    }
}
