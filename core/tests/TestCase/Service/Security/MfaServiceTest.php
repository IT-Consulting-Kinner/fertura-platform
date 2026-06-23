<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Security;

use App\Service\Security\MfaService;
use App\Service\Security\Totp;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Test of MFA management: two-step enrollment (wrong code is rejected, the
 * secret is stored encrypted in the DB), TOTP verification with
 * **replay protection**, single-use recovery codes, and deactivation.
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

        // Wrong code -> no enrollment.
        $this->assertNull($mfa->confirmEnrollment($this->userId, $secret, '000000'));
        $this->assertFalse($mfa->enabled($this->userId));

        // Valid code -> active + 8 recovery codes.
        $codes = $mfa->confirmEnrollment($this->userId, $secret, Totp::code($secret));
        $this->assertNotNull($codes);
        $this->assertCount(MfaService::RECOVERY_CODE_COUNT, $codes);
        $this->assertTrue($mfa->enabled($this->userId));
        $this->assertSame(MfaService::RECOVERY_CODE_COUNT, $mfa->recoveryCodesLeft($this->userId));

        // The secret is NEVER stored in plaintext in the DB.
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
        // Replay of the same code (same time step) -> rejected.
        $this->assertFalse($mfa->verify($this->userId, $code));
        // Wrong code -> rejected.
        $this->assertFalse($mfa->verify($this->userId, '000000'));
    }

    public function testReplayStepIsPersistedDurablyInDb(): void
    {
        // F12: the accepted time step is persisted on the user row (durable, atomic
        // conditional UPDATE), not in a best-effort cache — so a cache outage can no
        // longer silently re-enable replay of an intercepted code.
        $mfa = new MfaService();
        $secret = Totp::generateSecret();
        $mfa->confirmEnrollment($this->userId, $secret, Totp::code($secret));

        $this->assertTrue($mfa->verify($this->userId, Totp::code($secret)));
        $step = ConnectionManager::get('default')->execute(
            'SELECT totp_last_step FROM users WHERE id = :id',
            ['id' => $this->userId],
        )->fetch('assoc')['totp_last_step'];
        $this->assertNotNull($step, 'the accepted TOTP time step is persisted in the DB');
        $this->assertGreaterThan(0, (int)$step);
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
        // Second redemption of the same code -> rejected.
        $this->assertFalse($mfa->verify($this->userId, $recovery));
        // Also redeemable in normalized form (lowercase, no hyphen): the next code.
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
