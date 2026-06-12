<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Service\Security\Cbor;
use App\Service\Security\MfaService;
use App\Service\Security\Totp;
use App\Service\Security\WebAuthnService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integration test of MFA self-management (`/mfa`): status, two-step TOTP setup
 * (setup → confirmation → recovery codes), deactivation, and the passkey
 * endpoints (options gate, registration, deletion). The logged-in user is set
 * via the session.
 */
class MfaControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->userId = (string)ConnectionManager::get('default')->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_mfac_' . bin2hex(random_bytes(4)), 'e' => 'mfac_' . bin2hex(random_bytes(4)) . '@zzmfac.local'],
        )->fetch('assoc')['id'];
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        ConnectionManager::get('default')->execute("DELETE FROM users WHERE email LIKE '%@zzmfac.local'");
    }

    /** @param array<string,mixed> $extra */
    private function auth(array $extra = []): void
    {
        $this->session($extra + ['Auth' => ['id' => $this->userId, 'username' => 'zztest_mfac', 'email' => 'm@zzmfac.local']]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
    }

    private function enrollTotp(): string
    {
        $secret = Totp::generateSecret();
        (new MfaService())->confirmEnrollment($this->userId, $secret, Totp::code($secret));

        return $secret;
    }

    public function testIndexRendersDisabledThenEnabled(): void
    {
        $this->auth();
        $this->get('/mfa');
        $this->assertResponseOk();
        $this->assertResponseContains('2FA');

        $this->enrollTotp();
        $this->auth();
        $this->get('/mfa');
        $this->assertResponseOk();
        $this->assertResponseContains('Passkey'); // passkey section appears when 2FA is active
    }

    public function testSetupRendersSecret(): void
    {
        $this->auth();
        $this->post('/mfa/setup');
        $this->assertResponseOk();
        $this->assertResponseContains('otpauth://'); // QR/manual key
        $this->assertNotEmpty($_SESSION['Mfa']['setup_secret'] ?? null);
    }

    public function testConfirmActivatesWithValidCodeAndRejectsInvalid(): void
    {
        $secret = Totp::generateSecret();

        // Invalid code -> setup again, not activated.
        $this->auth(['Mfa' => ['setup_secret' => $secret]]);
        $this->post('/mfa/confirm', ['code' => '000000']);
        $this->assertResponseOk();
        $this->assertFalse((new MfaService())->enabled($this->userId));

        // Valid code -> recovery codes view + active.
        $this->auth(['Mfa' => ['setup_secret' => $secret]]);
        $this->post('/mfa/confirm', ['code' => Totp::code($secret)]);
        $this->assertResponseOk();
        $this->assertTrue((new MfaService())->enabled($this->userId));
        $this->assertSame(MfaService::RECOVERY_CODE_COUNT, (new MfaService())->recoveryCodesLeft($this->userId));
    }

    public function testConfirmWithoutPendingSecretRedirects(): void
    {
        $this->auth();
        $this->post('/mfa/confirm', ['code' => '123456']);
        $this->assertRedirect(['action' => 'index']);
    }

    public function testDisableRequiresValidCode(): void
    {
        $secret = $this->enrollTotp();

        // Wrong code -> stays active.
        $this->auth();
        $this->post('/mfa/disable', ['code' => '000000']);
        $this->assertRedirect(['action' => 'index']);
        $this->assertTrue((new MfaService())->enabled($this->userId));

        // Valid code -> deactivated.
        $this->auth();
        $this->post('/mfa/disable', ['code' => Totp::code($secret)]);
        $this->assertFalse((new MfaService())->enabled($this->userId));
    }

    public function testPasskeyOptionsGatedOnTotp(): void
    {
        // Without TOTP -> 409 (passkey only as a convenient alternative AFTER TOTP).
        $this->auth();
        $this->get('/mfa/passkeyOptions');
        $this->assertResponseCode(409);

        // With TOTP -> JSON options incl. challenge.
        $this->enrollTotp();
        $this->auth();
        $this->get('/mfa/passkeyOptions');
        $this->assertResponseOk();
        $opts = (array)json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('challenge', $opts);
        $this->assertSame('localhost', $opts['rp']['id']);
    }

    public function testPasskeyRegisterAndDelete(): void
    {
        $this->enrollTotp();
        $rpId = 'localhost';
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        assert($key !== false);
        $credId = random_bytes(16);
        $details = openssl_pkey_get_details($key);
        $cose = Cbor::encode([
            1 => 2, 3 => -7, -1 => 1,
            -2 => str_pad((string)$details['ec']['x'], 32, "\x00", STR_PAD_LEFT),
            -3 => str_pad((string)$details['ec']['y'], 32, "\x00", STR_PAD_LEFT),
        ]);
        $challenge = WebAuthnService::challenge();
        $authData = hash('sha256', $rpId, true) . chr(0x41) . pack('N', 0)
            . str_repeat("\x00", 16) . pack('n', strlen($credId)) . $credId . $cose;
        $clientData = (string)json_encode(['type' => 'webauthn.create', 'challenge' => $challenge, 'origin' => 'http://' . $rpId]);

        $this->auth(['Mfa' => ['passkey_challenge' => $challenge]]);
        $this->post('/mfa/passkeyRegister', [
            'client_data' => WebAuthnService::b64uEncode($clientData),
            'attestation' => WebAuthnService::b64uEncode(Cbor::encode(['fmt' => 'none', 'attStmt' => [], 'authData' => $authData])),
            'label' => 'Test-Key',
        ]);
        $this->assertRedirect(['action' => 'index']);
        $creds = (new WebAuthnService())->credentials($this->userId);
        $this->assertCount(1, $creds);

        // Delete.
        $this->auth();
        $this->post('/mfa/passkeyDelete/' . $creds[0]['id']);
        $this->assertRedirect(['action' => 'index']);
        $this->assertFalse((new WebAuthnService())->hasCredentials($this->userId));
    }

    public function testPasskeyRegisterWithoutChallengeRedirects(): void
    {
        $this->enrollTotp();
        $this->auth(); // no passkey_challenge in the session
        $this->post('/mfa/passkeyRegister', ['client_data' => 'x', 'attestation' => 'y']);
        $this->assertRedirect(['action' => 'index']);
        $this->assertFalse((new WebAuthnService())->hasCredentials($this->userId));
    }
}
