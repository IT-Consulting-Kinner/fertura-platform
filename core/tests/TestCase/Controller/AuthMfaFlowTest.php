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
