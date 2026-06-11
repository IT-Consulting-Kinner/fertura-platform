<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Security;

use App\Service\Security\MfaService;
use App\Service\Security\Totp;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Test der MFA-Verwaltung: zweistufiges Enrollment (falscher Code lehnt ab,
 * Secret landet verschlüsselt in der DB), TOTP-Verifikation mit
 * **Replay-Schutz**, einmal-verwendbare Recovery-Codes und Deaktivierung.
 */
class MfaServiceTest extends TestCase
{
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->userId = (string)ConnectionManager::get('default')->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_mfa_' . bin2hex(random_bytes(4)), 'e' => 'mfa_' . bin2hex(random_bytes(4)) . '@zzmfa.local'],
        )->fetch('assoc')['id'];
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        ConnectionManager::get('default')->execute("DELETE FROM users WHERE email LIKE '%@zzmfa.local'");
    }

    public function testEnrollmentRequiresValidCodeAndStoresEncrypted(): void
    {
        $mfa = new MfaService();
        $secret = Totp::generateSecret();

        // Falscher Code -> kein Enrollment.
        $this->assertNull($mfa->confirmEnrollment($this->userId, $secret, '000000'));
        $this->assertFalse($mfa->enabled($this->userId));

        // Gültiger Code -> aktiv + 8 Recovery-Codes.
        $codes = $mfa->confirmEnrollment($this->userId, $secret, Totp::code($secret));
        $this->assertNotNull($codes);
        $this->assertCount(MfaService::RECOVERY_CODE_COUNT, $codes);
        $this->assertTrue($mfa->enabled($this->userId));
        $this->assertSame(MfaService::RECOVERY_CODE_COUNT, $mfa->recoveryCodesLeft($this->userId));

        // Secret liegt NIE im Klartext in der DB.
        $stored = (string)ConnectionManager::get('default')->execute(
            'SELECT totp_secret FROM users WHERE id = :id',
            ['id' => $this->userId],
        )->fetch('assoc')['totp_secret'];
        $this->assertStringNotContainsString($secret, $stored);
    }

    public function testVerifyAcceptsOnceThenBlocksReplay(): void
    {
        $mfa = new MfaService();
        $secret = Totp::generateSecret();
        $mfa->confirmEnrollment($this->userId, $secret, Totp::code($secret));

        $code = Totp::code($secret);
        $this->assertTrue($mfa->verify($this->userId, $code));
        // Replay desselben Codes (gleicher Zeitschritt) -> abgelehnt.
        $this->assertFalse($mfa->verify($this->userId, $code));
        // Falscher Code -> abgelehnt.
        $this->assertFalse($mfa->verify($this->userId, '000000'));
    }

    public function testRecoveryCodeIsSingleUse(): void
    {
        $mfa = new MfaService();
        $secret = Totp::generateSecret();
        $codes = $mfa->confirmEnrollment($this->userId, $secret, Totp::code($secret));
        $this->assertNotNull($codes);

        $recovery = $codes[0];
        $this->assertTrue($mfa->verify($this->userId, $recovery));
        $this->assertSame(MfaService::RECOVERY_CODE_COUNT - 1, $mfa->recoveryCodesLeft($this->userId));
        // Zweite Einlösung desselben Codes -> abgelehnt.
        $this->assertFalse($mfa->verify($this->userId, $recovery));
        // Auch normalisiert (klein, ohne Bindestrich) einlösbar: nächster Code.
        $this->assertTrue($mfa->verify($this->userId, strtolower(str_replace('-', '', $codes[1]))));
    }

    public function testDisableClearsSecretAndCodes(): void
    {
        $mfa = new MfaService();
        $secret = Totp::generateSecret();
        $mfa->confirmEnrollment($this->userId, $secret, Totp::code($secret));

        $mfa->disable($this->userId);

        $this->assertFalse($mfa->enabled($this->userId));
        $this->assertSame(0, $mfa->recoveryCodesLeft($this->userId));
        $row = ConnectionManager::get('default')->execute(
            'SELECT totp_secret FROM users WHERE id = :id',
            ['id' => $this->userId],
        )->fetch('assoc');
        $this->assertNull($row['totp_secret']);
    }
}
