<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integration test of the local login path (ch. 27.2.2) — verifies the form
 * authenticator with a directly configured password identifier (no longer the
 * deprecated loadIdentifier()), the `active` finder, and Argon2id hashing.
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
            'INSERT INTO users (username, email, status, password_hash) '
            . "VALUES (:u, :e, 'active', :h) RETURNING id",
            ['u' => $this->username, 'e' => $this->username . '@invalid.local', 'h' => $hash],
        )->fetch('assoc');
        $this->userId = (string)$row['id'];
        $this->enableCsrfToken();
        $this->enableRetainFlashMessages();
        // Client IP for the login protection (auth_failures.ip_address is inet);
        // in real operation always set via REMOTE_ADDR.
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
        // The controller redirects ONLY on valid authentication (302) ->
        // proves that form authenticator + identifier + hasher take effect.
        $this->assertResponseCode(302);
        $this->assertResponseNotContains('flash.auth.invalid');
    }

    public function testWrongPasswordDoesNotAuthenticate(): void
    {
        $this->post('/login', ['username' => $this->username, 'password' => 'falsch']);
        // Failure -> login page is re-rendered (no redirect).
        $this->assertResponseCode(200);
    }
}
