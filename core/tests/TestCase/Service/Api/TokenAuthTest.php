<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Api;

use App\Service\Api\TokenService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integrationstest der externen API-Authentifizierung (Kap. 29, E49) gegen die
 * Test-DB: TokenService (Erzeugung/Hash-only/Authentisierung/Ablauf/Widerruf)
 * und der HTTP-Pfad über die ApiAuthMiddleware (Bearer-Token, Scope-Gate,
 * 401/403). Token-Klartext existiert nur bei der Erzeugung; gespeichert wird
 * nur der SHA-256-Hash.
 */
class TokenAuthTest extends TestCase
{
    use IntegrationTestTrait;

    private string $userId = '';
    private TokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = bin2hex(random_bytes(5));
        $row = ConnectionManager::get('default')->execute(
            "INSERT INTO users (username, email, status, locale) "
            . "VALUES (:u, :e, 'active', 'en_US') RETURNING id",
            ['u' => 'apitest_' . $suffix, 'e' => 'apitest_' . $suffix . '@invalid.local'],
        )->fetch('assoc');
        $this->userId = (string)$row['id'];
        $this->tokens = new TokenService();
    }

    protected function tearDown(): void
    {
        // api_tokens.user_id ist ON DELETE CASCADE -> Tokens gehen mit.
        ConnectionManager::get('default')->execute('DELETE FROM users WHERE id = :id', ['id' => $this->userId]);
        parent::tearDown();
    }

    public function testCreateReturnsPlaintextOnceAndStoresOnlyHash(): void
    {
        $res = $this->tokens->create($this->userId, 'Test-Token', ['me:read'], null, null);
        $this->assertArrayHasKey('token', $res);
        $this->assertStringStartsWith('ftra_', $res['token']);

        // In der DB darf NUR der Hash liegen, nie der Klartext.
        $stored = ConnectionManager::get('default')->execute(
            'SELECT token_hash FROM api_tokens WHERE id = :id',
            ['id' => $res['id']],
        )->fetch('assoc');
        $this->assertSame(TokenService::hash($res['token']), $stored['token_hash']);
        $this->assertNotSame($res['token'], $stored['token_hash']);
    }

    public function testAuthenticateValidToken(): void
    {
        $res = $this->tokens->create($this->userId, 'A', ['me:read', 'modules:read'], null, null);
        $auth = $this->tokens->authenticate($res['token']);
        $this->assertNotNull($auth);
        $this->assertSame($this->userId, $auth['user_id']);
        $this->assertSame(['me:read', 'modules:read'], $auth['scopes']);
    }

    public function testAuthenticateRejectsUnknownAndMalformed(): void
    {
        $this->assertNull($this->tokens->authenticate('ftra_voellig-unbekannt'));
        $this->assertNull($this->tokens->authenticate('ohne-prefix'));
    }

    public function testExpiredTokenRejected(): void
    {
        $res = $this->tokens->create($this->userId, 'Alt', ['me:read'], '2000-01-01T00:00:00+00:00', null);
        $this->assertNull($this->tokens->authenticate($res['token']));
    }

    public function testRevokedTokenRejected(): void
    {
        $res = $this->tokens->create($this->userId, 'Widerruf', ['me:read'], null, null);
        $this->assertNotNull($this->tokens->authenticate($res['token']));
        $this->assertTrue($this->tokens->revoke($res['id'], $this->userId));
        $this->assertNull($this->tokens->authenticate($res['token']));
        // Idempotent: zweiter Widerruf meldet "nichts geändert".
        $this->assertFalse($this->tokens->revoke($res['id'], $this->userId));
    }

    public function testHttpMeWithValidTokenReturnsIdentity(): void
    {
        $res = $this->tokens->create($this->userId, 'HTTP', ['me:read'], null, null);
        $this->configRequest(['headers' => [
            'Authorization' => 'Bearer ' . $res['token'],
            'Accept' => 'application/json',
        ]]);
        $this->get('/api/v1/me');
        $this->assertResponseOk();
        $body = (array)json_decode((string)$this->_response->getBody(), true);
        $this->assertSame($this->userId, $body['user_id'] ?? null);
        $this->assertContains('me:read', (array)($body['scopes'] ?? []));
    }

    public function testHttpMeWithoutTokenIsUnauthorized(): void
    {
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/v1/me');
        $this->assertResponseCode(401);
    }

    public function testHttpMeWithInsufficientScopeIsForbidden(): void
    {
        // Token ohne me:read -> Middleware lässt durch (Token gültig), aber der
        // Controller verweigert wegen fehlendem Scope (403).
        $res = $this->tokens->create($this->userId, 'NurHealth', ['health:read'], null, null);
        $this->configRequest(['headers' => [
            'Authorization' => 'Bearer ' . $res['token'],
            'Accept' => 'application/json',
        ]]);
        $this->get('/api/v1/me');
        $this->assertResponseCode(403);
        $this->assertResponseContains('insufficient_scope');
    }
}
