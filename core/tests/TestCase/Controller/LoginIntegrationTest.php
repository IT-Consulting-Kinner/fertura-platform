<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integrationstest des lokalen Login-Pfads (Kap. 27.2.2) — verifiziert den
 * Form-Authenticator mit direkt konfiguriertem Password-Identifier (nicht mehr
 * das veraltete loadIdentifier()), Finder `active` und Argon2id-Hashing.
 */
class LoginIntegrationTest extends TestCase
{
    use IntegrationTestTrait;

    private string $userId = '';
    private string $username = '';
    private const PASSWORD = 'Korrekt!Passwort123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'logintest_' . bin2hex(random_bytes(5));
        $hash = password_hash(self::PASSWORD, PASSWORD_ARGON2ID);
        $row = ConnectionManager::get('default')->execute(
            "INSERT INTO users (username, email, status, password_hash) "
            . "VALUES (:u, :e, 'active', :h) RETURNING id",
            ['u' => $this->username, 'e' => $this->username . '@invalid.local', 'h' => $hash],
        )->fetch('assoc');
        $this->userId = (string)$row['id'];
        $this->enableCsrfToken();
        $this->enableRetainFlashMessages();
        // Client-IP für den Anmeldeschutz (auth_failures.ip_address ist inet);
        // im realen Betrieb immer durch REMOTE_ADDR gesetzt.
        $this->configRequest(['environment' => ['REMOTE_ADDR' => '127.0.0.1']]);
    }

    protected function tearDown(): void
    {
        ConnectionManager::get('default')->execute('DELETE FROM users WHERE id = :id', ['id' => $this->userId]);
        ConnectionManager::get('default')->execute('DELETE FROM auth_failures WHERE identifier = :u', ['u' => $this->username]);
        parent::tearDown();
    }

    public function testValidLoginRedirects(): void
    {
        $this->post('/login', ['username' => $this->username, 'password' => self::PASSWORD]);
        // Der Controller leitet NUR bei gültiger Authentifizierung um (302) ->
        // beweist, dass Form-Authenticator + Identifier + Hasher greifen.
        $this->assertResponseCode(302);
        $this->assertResponseNotContains('flash.auth.invalid');
    }

    public function testWrongPasswordDoesNotAuthenticate(): void
    {
        $this->post('/login', ['username' => $this->username, 'password' => 'falsch']);
        // Fehlschlag -> Login-Seite wird neu gerendert (kein Redirect).
        $this->assertResponseCode(200);
    }
}
