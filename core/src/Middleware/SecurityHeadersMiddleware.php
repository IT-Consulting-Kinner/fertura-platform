<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\Settings\SettingsManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Sets security-relevant response headers on EVERY response (including error
 * pages): CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy
 * and — only over TLS — HSTS.
 *
 * The default CSP fits the SSR frontend: assets are exclusively self-hosted
 * (no CDN), inline scripts/styles are used by UI-Kit/Bootstrap markup
 * (`unsafe-inline`), and framing is entirely forbidden. Operators can replace the
 * policy via `core.security.csp` or disable the headers via
 * `core.security.headers.enabled` (e.g. when a proxy sets them); already-set
 * headers are never overwritten.
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private const DEFAULT_CSP = "default-src 'self'; script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; "
        . "connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; "
        . "object-src 'none'";

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        [$enabled, $csp, $hstsMaxAge] = $this->config();
        if (!$enabled) {
            return $response;
        }

        $headers = [
            'Content-Security-Policy' => $csp,
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // Sensitive browser features that the frontend does not use.
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];
        // Send HSTS only over TLS (otherwise ineffective, or harmful if
        // misconfigured); behind a TLS-terminating proxy X-Forwarded-Proto counts.
        $https = $request->getUri()->getScheme() === 'https'
            || strtolower((string)($request->getServerParams()['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        if ($https && $hstsMaxAge > 0) {
            $headers['Strict-Transport-Security'] = 'max-age=' . $hstsMaxAge . '; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    /** @return array{0:bool,1:string,2:int} [enabled, csp, hstsMaxAge] */
    private function config(): array
    {
        try {
            $settings = new SettingsManager();
            $enabled = (bool)$settings->get('core', 'security.headers.enabled', true);
            $csp = trim((string)($settings->get('core', 'security.csp', '') ?? ''));
            $hsts = (int)$settings->get('core', 'security.hsts_max_age', 31536000);
        } catch (Throwable) {
            // Fail-safe: without reachable settings (bootstrap/migration) the
            // secure defaults apply — better to set the headers than to omit them.
            [$enabled, $csp, $hsts] = [true, '', 31536000];
        }

        return [$enabled, $csp !== '' ? $csp : self::DEFAULT_CSP, $hsts];
    }
}
