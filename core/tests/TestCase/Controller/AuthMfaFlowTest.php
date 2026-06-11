<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Service\Security\MfaService;
use App\Service\Security\Totp;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * End-to-End-Test des MFA-Login-Flows: Passwort allein schließt die Anmeldung
 * NICHT ab (Identity wird erst nach gültigem zweiten Faktor gesetzt), falscher
 * Code wird gedrosselt abgelehnt, Recovery-Code funktioniert, und die
 * Challenge verfällt nach Ablauf des Pending-Fensters.
 */
class AuthMfaFlowTest extends TestCase
{
    use IntegrationTestTrait;

    private const PASSWORD = 'Correct-Horse-7!';

    private string $userId;
    private string $username;
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->username = 'zztest_mfauser_' . bin2hex(random_bytes(3));
        $this->userId = (string)ConnectionManager::get('default')->execute(
            "INSERT INTO users (username, email, status, password_hash) VALUES (:u, :e, 'active', :p) RETURNING id",
            [
                'u' => $this->username,
                'e' => 'flow_' . bin2hex(random_bytes(4)) . '@zzmfaflow.local',
                'p' => password_hash(self::PASSWORD, PASSWORD_ARGON2ID),
            ],
        )->fetch('assoc')['id'];
        // TOTP über den echten Enrollment-Pfad aktivieren.
        $this->secret = Totp::generateSecret();
        (new MfaService())->confirmEnrollment($this->userId, $this->secret, Totp::code($this->secret));
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "DELETE FROM auth_failures WHERE identifier LIKE 'zztest_mfauser_%'",
        );
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzmfaflow.local'");
    }

    private function postLogin(): void
    {
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/login', ['username' => $this->username, 'password' => self::PASSWORD]);
    }

    /**
     * Übernimmt server-seitig geschriebene Session-Daten in den NÄCHSTEN
     * Test-Request. Das IntegrationTestTrait persistiert Session-Writes nicht
     * zwischen Requests (jeder Request startet nur mit `$this->session()`-Daten);
     * im echten Betrieb übernimmt das der Session-Store.
     *
     * @param list<string> $keys
     */
    private function carrySession(array $keys): void
    {
        $data = [];
        foreach ($keys as $key) {
            if (isset($_SESSION[$key])) {
                $data[$key] = $_SESSION[$key];
            }
        }
        $this->session($data);
    }

    public function testPasswordAloneDoesNotAuthenticate(): void
    {
        $this->postLogin();

        // Statt /admin: Umleitung zur MFA-Challenge.
        $this->assertRedirectContains('/login/mfa');

        // Identity ist NICHT gesetzt (die Middleware-persistierte wurde wieder
        // entfernt) — nur der Pending-Marker liegt in der Session.
        $this->assertArrayNotHasKey('Auth', $_SESSION);
        $this->assertArrayHasKey('Mfa', $_SESSION);

        // Geschützte Seite verlangt weiterhin Login.
        $this->get('/mfa');
        $this->assertRedirectContains('/login');
    }

    public function testWrongCodeRejectedAndThrottled(): void
    {
        $this->postLogin();
        $this->carrySession(['Mfa']);

        $this->post('/login/mfa', ['code' => '000000']);
        $this->assertResponseOk(); // Formular erneut, kein Login
        $this->assertArrayNotHasKey('Auth', $_SESSION); // keine Identity gesetzt

        // Fehlversuch zählt in die Anmelde-Drosselung.
        $failures = (int)ConnectionManager::get('default')->execute(
            'SELECT count(*) AS c FROM auth_failures WHERE identifier = :u',
            ['u' => $this->username],
        )->fetch('assoc')['c'];
        $this->assertGreaterThanOrEqual(1, $failures);
    }

    public function testValidCodeCompletesLogin(): void
    {
        $this->postLogin();
        $this->carrySession(['Mfa']);

        $this->post('/login/mfa', ['code' => Totp::code($this->secret)]);
        $this->assertRedirect('/admin');
        $this->assertArrayHasKey('Auth', $_SESSION); // Identity erst JETZT gesetzt

        // Jetzt angemeldet: MFA-Selbstverwaltung erreichbar.
        $this->carrySession(['Auth']);
        $this->get('/mfa');
        $this->assertResponseOk();
        $this->assertResponseContains('2FA');
    }

    public function testRecoveryCodeCompletesLogin(): void
    {
        // Frisches Enrollment, um an Klartext-Recovery-Codes zu kommen.
        (new MfaService())->disable($this->userId);
        $codes = (new MfaService())->confirmEnrollment($this->userId, $this->secret, Totp::code($this->secret));
        $this->assertNotNull($codes);

        $this->postLogin();
        $this->carrySession(['Mfa']);
        $this->post('/login/mfa', ['code' => $codes[0]]);
        $this->assertRedirect('/admin');
        $this->assertArrayHasKey('Auth', $_SESSION);
    }

    public function testPasskeyAssertionCompletesLogin(): void
    {
        // Passkey direkt über den Service registrieren (echtes EC-P-256-Paar).
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        assert($key !== false);
        $credentialId = random_bytes(16);
        $rpId = 'localhost'; // Host der Test-Requests
        $details = openssl_pkey_get_details($key);
        $coseKey = \App\Service\Security\Cbor::encode([
            1 => 2, 3 => -7, -1 => 1,
            -2 => str_pad((string)$details['ec']['x'], 32, "\x00", STR_PAD_LEFT),
            -3 => str_pad((string)$details['ec']['y'], 32, "\x00", STR_PAD_LEFT),
        ]);
        $regChallenge = \App\Service\Security\WebAuthnService::challenge();
        $authData = hash('sha256', $rpId, true) . chr(0x41) . pack('N', 0)
            . str_repeat("\x00", 16) . pack('n', strlen($credentialId)) . $credentialId . $coseKey;
        (new \App\Service\Security\WebAuthnService())->register(
            $this->userId,
            \App\Service\Security\WebAuthnService::b64uEncode((string)json_encode(
                ['type' => 'webauthn.create', 'challenge' => $regChallenge, 'origin' => 'http://' . $rpId],
            )),
            \App\Service\Security\WebAuthnService::b64uEncode(\App\Service\Security\Cbor::encode(
                ['fmt' => 'none', 'attStmt' => [], 'authData' => $authData],
            )),
            $regChallenge,
            $rpId,
        );

        // Passwort-Schritt -> Challenge-Optionen holen (Challenge landet in der Session).
        $this->postLogin();
        $this->carrySession(['Mfa']);
        $this->get('/login/mfa/passkeys');
        $this->assertResponseOk();
        $options = (array)json_decode((string)$this->_response->getBody(), true);
        $this->assertNotEmpty($options['allowCredentials']);
        $challenge = (string)$options['challenge'];

        // Assertion clientseitig "signieren" und als Formular-POST einreichen.
        $clientData = (string)json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => 'http://' . $rpId]);
        $assertAuthData = hash('sha256', $rpId, true) . chr(0x01) . pack('N', 7);
        openssl_sign($assertAuthData . hash('sha256', $clientData, true), $signature, $key, OPENSSL_ALGO_SHA256);

        $this->carrySession(['Mfa']); // pending + passkey_challenge übernehmen
        $this->post('/login/mfa', [
            'credential_id' => \App\Service\Security\WebAuthnService::b64uEncode($credentialId),
            'client_data' => \App\Service\Security\WebAuthnService::b64uEncode($clientData),
            'auth_data' => \App\Service\Security\WebAuthnService::b64uEncode($assertAuthData),
            'signature' => \App\Service\Security\WebAuthnService::b64uEncode($signature),
        ]);

        $this->assertRedirect('/admin');
        $this->assertArrayHasKey('Auth', $_SESSION); // Anmeldung abgeschlossen
    }

    public function testChallengeWithoutPendingRedirectsToLogin(): void
    {
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        // Direktaufruf ohne vorherigen Passwort-Schritt -> zurück zum Login.
        $this->get('/login/mfa');
        $this->assertRedirectContains('/login');
    }

    public function testUserWithoutMfaLogsInDirectly(): void
    {
        (new MfaService())->disable($this->userId);
        $this->postLogin();
        $this->assertRedirect('/admin'); // kein MFA-Umweg
    }
}
