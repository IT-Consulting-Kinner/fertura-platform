<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Auth\Sso;

use App\Service\Auth\Sso\SsoService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use RuntimeException;

/**
 * Tests SSO management + just-in-time provisioning/linking (P06).
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
        $conn->execute("DELETE FROM saml_auth_requests WHERE relay_state LIKE 'zz_%'");
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zztest.local'");
        $conn->execute("DELETE FROM sso_providers WHERE name LIKE 'zztest_%'");
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zzsso-%'");
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
        // Account-takeover protection: an existing account WITH a local password
        // must not be taken over via a claimed email address.
        ConnectionManager::get('default')->execute(
            'INSERT INTO users (username, email, status, password_hash) '
            . "VALUES ('zztest_local', 'local@zztest.local', 'active', 'hash')",
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/lokal/');
        (new SsoService())->loginExternalUser($this->providerId, 'ext-4', 'local@zztest.local', null, null);
    }

    public function testRejectsCrossTenantEmailMatch(): void
    {
        // Peer-review F1: an IdP (here the default-tenant provider) must not log in
        // as a user that belongs to ANOTHER tenant just because it asserts that
        // user's email. The match is rejected, never linked or provisioned.
        $conn = ConnectionManager::get('default');
        $tenantB = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zzsso-' || substr(md5(random()::text), 1, 8), 'ZZ SSO B') RETURNING id",
        )->fetch('assoc')['id'];
        $victim = (string)$conn->execute(
            "INSERT INTO users (username, email, status, tenant_id) VALUES ('zztest_vicb', 'vic@zztest.local', 'active', :t) RETURNING id",
            ['t' => $tenantB],
        )->fetch('assoc')['id'];

        try {
            (new SsoService())->loginExternalUser($this->providerId, 'ext-x', 'vic@zztest.local', null, null, true);
            $this->fail('cross-tenant email match must be rejected');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/anderen Mandanten/', $e->getMessage());
        }
        $this->assertSame(0, (int)$conn->execute(
            'SELECT count(*) c FROM identity_links WHERE user_id = :u',
            ['u' => $victim],
        )->fetch('assoc')['c'], 'no identity link is created to a foreign-tenant user');
    }

    public function testProvisionsIntoProviderTenant(): void
    {
        // Peer-review F4: a JIT-provisioned user lands in the AUTHENTICATING
        // provider's tenant, not the users.tenant_id column default (operator tenant).
        $conn = ConnectionManager::get('default');
        $tenantB = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zzsso-' || substr(md5(random()::text), 1, 8), 'ZZ SSO B') RETURNING id",
        )->fetch('assoc')['id'];
        $providerB = (new SsoService())->createProvider('oidc', 'zztest_idp_b', [], null, 'B')['id'];
        $conn->execute('UPDATE sso_providers SET tenant_id = :t WHERE id = :id', ['t' => $tenantB, 'id' => $providerB]);

        $userId = (new SsoService())->loginExternalUser($providerB, 'ext-b', 'newb@zztest.local', null, null, true);
        $this->assertSame($tenantB, (string)$conn->execute(
            'SELECT tenant_id FROM users WHERE id = :id',
            ['id' => $userId],
        )->fetch('assoc')['tenant_id'], 'JIT user is provisioned into the provider tenant');
    }

    public function testRefusesUnverifiedEmail(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unverifiziert/');
        (new SsoService())->loginExternalUser($this->providerId, 'ext-5', 'new@zztest.local', null, null, false);
    }

    public function testSamlRequestStoreIsOneTimeUse(): void
    {
        // Cookie-independent replay protection: RelayState is redeemable exactly once.
        $svc = new SsoService();
        $relay = 'zz_' . bin2hex(random_bytes(8));
        $svc->rememberSamlRequest($relay, $this->providerId, '_req-123', 600);

        $first = $svc->consumeSamlRequest($relay);
        $this->assertNotNull($first);
        $this->assertSame($this->providerId, $first['provider_id']);
        $this->assertSame('_req-123', $first['request_id']);

        // The second redemption fails (consumed -> replay rejected).
        $this->assertNull($svc->consumeSamlRequest($relay));
        $this->assertNull($svc->consumeSamlRequest('zz_unknown'));
        $this->assertNull($svc->consumeSamlRequest(''));
    }

    public function testExpiredSamlRequestIsRejected(): void
    {
        $svc = new SsoService();
        $relay = 'zz_' . bin2hex(random_bytes(8));
        $svc->rememberSamlRequest($relay, $this->providerId, '_req-x', -1); // already expired
        $this->assertNull($svc->consumeSamlRequest($relay));
    }

    public function testProviderReturnsNullForMalformedId(): void
    {
        // `id` is a uuid column: a non-UUID value must not raise a Postgres
        // QueryException (SQLSTATE 22P02 -> 500 + stack trace in the log), but is
        // instead treated like an unknown provider (null).
        $svc = new SsoService();
        $this->assertNull($svc->provider('nonexistent'));
        $this->assertNull($svc->provider(str_repeat('0', 36)), '36-stellig, aber kein gültiges UUID');
        $this->assertNull($svc->provider(''));
    }
}
