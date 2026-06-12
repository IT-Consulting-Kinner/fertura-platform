<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Tests the security response headers (SecurityHeadersMiddleware): every response —
 * including the unauthenticated login page — carries CSP, X-Frame-Options DENY,
 * nosniff, Referrer and Permissions policies; HSTS is NOT sent over plaintext
 * HTTP (ineffective and a downgrade risk without TLS).
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
        // No HSTS over http (test requests run without TLS).
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
