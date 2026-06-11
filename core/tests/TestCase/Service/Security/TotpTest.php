<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Security;

use App\Service\Security\Totp;
use Cake\TestSuite\TestCase;

/**
 * Test des abhängigkeitsfreien TOTP (RFC 6238/4226): offizielle RFC-Testvektoren
 * (SHA1, auf 6 Ziffern gekürzt), Zeitfenster-Toleranz, Format-Ablehnung und
 * otpauth-URI.
 */
class TotpTest extends TestCase
{
    /** RFC-6238-Secret "12345678901234567890" als Base32. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testRfc6238Vectors(): void
    {
        // RFC 6238 Anhang B (SHA1, 8-stellig) -> letzte 6 Ziffern.
        $this->assertSame('287082', Totp::code(self::RFC_SECRET, 59));
        $this->assertSame('081804', Totp::code(self::RFC_SECRET, 1111111109));
        $this->assertSame('050471', Totp::code(self::RFC_SECRET, 1111111111));
        $this->assertSame('005924', Totp::code(self::RFC_SECRET, 1234567890));
        $this->assertSame('279037', Totp::code(self::RFC_SECRET, 2000000000));
    }

    public function testVerifyWindowAndRejection(): void
    {
        $t = 1111111109;
        $code = Totp::code(self::RFC_SECRET, $t);

        // Exakt + ±1 Zeitschritt (30 s Drift) akzeptiert.
        $this->assertNotNull(Totp::verify(self::RFC_SECRET, $code, 1, $t));
        $this->assertNotNull(Totp::verify(self::RFC_SECRET, $code, 1, $t + 30));
        $this->assertNotNull(Totp::verify(self::RFC_SECRET, $code, 1, $t - 30));
        // Außerhalb des Fensters abgelehnt.
        $this->assertNull(Totp::verify(self::RFC_SECRET, $code, 1, $t + 90));

        // Formatfehler abgelehnt (zu kurz, nicht-numerisch, trailing newline).
        $this->assertNull(Totp::verify(self::RFC_SECRET, '12345', 1, $t));
        $this->assertNull(Totp::verify(self::RFC_SECRET, 'abcdef', 1, $t));
        $this->assertNull(Totp::verify(self::RFC_SECRET, "287082\n1", 1, $t));
    }

    public function testGeneratedSecretRoundtrips(): void
    {
        $secret = Totp::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret); // 20 Byte -> 32 Base32-Zeichen

        $now = time();
        $code = Totp::code($secret, $now);
        $this->assertNotNull(Totp::verify($secret, $code, 0, $now));
    }

    public function testProvisioningUri(): void
    {
        $uri = Totp::provisioningUri('ABC234', 'max.mustermann', 'Fertura');
        $this->assertStringStartsWith('otpauth://totp/Fertura:max.mustermann?secret=ABC234', $uri);
        $this->assertStringContainsString('issuer=Fertura', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }
}
