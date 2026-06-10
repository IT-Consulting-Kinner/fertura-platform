<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Auth\Sso;

use App\Service\Auth\Sso\SsoService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Test der SSO-Verwaltung + Just-in-Time-Provisioning/Linking (P06).
 */
class SsoServiceTest extends TestCase
{
    private string $providerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->providerId = (new SsoService())->createProvider(
            'oidc',
            'zztest_idp',
            ['issuer' => 'https://idp', 'client_id' => 'c'],
            'sekret',
            'Login',
        )['id'];
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zztest.local'");
        $conn->execute("DELETE FROM sso_providers WHERE name LIKE 'zztest_%'");
    }

    public function testSecretIsEncryptedAndDecryptable(): void
    {
        $raw = ConnectionManager::get('default')->execute(
            'SELECT secret_encrypted FROM sso_providers WHERE id = :id',
            ['id' => $this->providerId],
        )->fetch('assoc');
        $this->assertNotSame('sekret', $raw['secret_encrypted'], 'Secret darf nicht im Klartext stehen.');
        $this->assertSame('sekret', (new SsoService())->provider($this->providerId)['secret']);
    }

    public function testProvisionsNewUserAndLinks(): void
    {
        $svc = new SsoService();
        $userId = $svc->loginExternalUser($this->providerId, 'ext-1', 'new@zztest.local', 'Erika', 'Muster');

        $conn = ConnectionManager::get('default');
        $user = $conn->execute('SELECT email, status, password_hash FROM users WHERE id = :id', ['id' => $userId])->fetch('assoc');
        $this->assertSame('new@zztest.local', $user['email']);
        $this->assertSame('active', $user['status']);
        $this->assertNull($user['password_hash'], 'SSO-Nutzer ohne Passwort.');

        $link = $conn->execute(
            'SELECT user_id FROM identity_links WHERE provider_id = :p AND subject = :s',
            ['p' => $this->providerId, 's' => 'ext-1'],
        )->fetch('assoc');
        $this->assertSame($userId, (string)$link['user_id']);
    }

    public function testReturningSubjectReusesSameUser(): void
    {
        $svc = new SsoService();
        $first = $svc->loginExternalUser($this->providerId, 'ext-2', 'again@zztest.local', null, null);
        $second = $svc->loginExternalUser($this->providerId, 'ext-2', 'changed@zztest.local', null, null);
        $this->assertSame($first, $second, 'gleicher Subject -> gleicher Benutzer (über den Link)');
    }

    public function testLinksExistingPasswordlessUserByEmail(): void
    {
        $conn = ConnectionManager::get('default');
        $existing = $conn->execute(
            "INSERT INTO users (username, email, status) VALUES ('zztest_pre', 'pre@zztest.local', 'active') RETURNING id",
        )->fetch('assoc')['id'];

        $userId = (new SsoService())->loginExternalUser($this->providerId, 'ext-3', 'pre@zztest.local', null, null);
        $this->assertSame((string)$existing, $userId, 'passwortloser Benutzer wird per E-Mail verknüpft, nicht dupliziert');
    }

    public function testRefusesMergeIntoLocalPasswordAccount(): void
    {
        // Account-Takeover-Schutz: ein bestehendes Konto MIT lokalem Passwort
        // darf nicht per behaupteter E-Mail übernommen werden.
        ConnectionManager::get('default')->execute(
            "INSERT INTO users (username, email, status, password_hash) "
            . "VALUES ('zztest_local', 'local@zztest.local', 'active', 'hash')",
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/lokal/');
        (new SsoService())->loginExternalUser($this->providerId, 'ext-4', 'local@zztest.local', null, null);
    }

    public function testRefusesUnverifiedEmail(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unverifiziert/');
        (new SsoService())->loginExternalUser($this->providerId, 'ext-5', 'new@zztest.local', null, null, false);
    }
}
