<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\Settings\SettingsManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Setzt sicherheitsrelevante Antwort-Header auf JEDER Antwort (inkl. Fehler-
 * seiten): CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy
 * und — nur über TLS — HSTS.
 *
 * Die Default-CSP passt zur SSR-Oberfläche: Assets ausschließlich self-hosted
 * (kein CDN), Inline-Skripte/-Styles werden von UI-Kit/Bootstrap-Markup genutzt
 * (`unsafe-inline`), Framing ist komplett verboten. Betreiber können die Policy
 * über `core.security.csp` ersetzen oder die Header per
 * `core.security.headers.enabled` abschalten (z. B. wenn ein Proxy sie setzt);
 * bereits gesetzte Header werden nie überschrieben.
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
            // Sensible Browser-Features, die die Oberfläche nicht nutzt.
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];
        // HSTS nur über TLS senden (sonst wirkungslos bzw. bei Fehlkonfiguration
        // schädlich); hinter einem TLS-terminierenden Proxy zählt X-Forwarded-Proto.
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
        } catch (\Throwable) {
            // Fail-safe: ohne erreichbare Settings (Bootstrap/Migration) gelten
            // die sicheren Defaults — Header lieber setzen als weglassen.
            [$enabled, $csp, $hsts] = [true, '', 31536000];
        }

        return [$enabled, $csp !== '' ? $csp : self::DEFAULT_CSP, $hsts];
    }
}
