<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Test der Security-Antwort-Header (SecurityHeadersMiddleware): jede Antwort —
 * auch die unauthentifizierte Login-Seite — trägt CSP, X-Frame-Options DENY,
 * nosniff, Referrer- und Permissions-Policy; HSTS wird über Klartext-HTTP
 * NICHT gesendet (wirkungslos/segregationsgefährlich ohne TLS).
 */
class SecurityHeadersMiddlewareTest extends TestCase
{
    use IntegrationTestTrait;

    public function testResponsesCarrySecurityHeaders(): void
    {
        $this->get('/login');

        $this->assertResponseOk();
        $csp = $this->_response->getHeaderLine('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertSame('DENY', $this->_response->getHeaderLine('X-Frame-Options'));
        $this->assertSame('nosniff', $this->_response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $this->_response->getHeaderLine('Referrer-Policy'));
        $this->assertStringContainsString('camera=()', $this->_response->getHeaderLine('Permissions-Policy'));
        // Kein HSTS über http (Test-Requests laufen ohne TLS).
        $this->assertFalse($this->_response->hasHeader('Strict-Transport-Security'));
    }

    public function testErrorResponsesCarryHeadersToo(): void
    {
        $this->get('/definitiv-nicht-vorhanden-' . bin2hex(random_bytes(4)));

        $this->assertResponseCode(404);
        $this->assertSame('nosniff', $this->_response->getHeaderLine('X-Content-Type-Options'));
        $this->assertNotEmpty($this->_response->getHeaderLine('Content-Security-Policy'));
    }
}
