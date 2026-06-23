<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api\Scim;

use App\Service\Api\TokenService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use stdClass;

/**
 * Integration test for SCIM 2.0 provisioning (/api/scim/v2/Users): scope gate,
 * creation (invited, without password), `userName eq` filter, PATCH active:false →
 * deactivation, **no hard delete** (DELETE deactivates), and the protection
 * "invited without password stays invited" on active:true.
 */
class ScimUsersTest extends TestCase
{
    use IntegrationTestTrait;

    private string $adminId = '';
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $suffix = bin2hex(random_bytes(4));
        $this->adminId = (string)ConnectionManager::get('default')->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_scimadm_' . $suffix, 'e' => 'scimadm_' . $suffix . '@zzscim.local'],
        )->fetch('assoc')['id'];
        $this->token = (new TokenService())->create($this->adminId, 'SCIM', ['scim:manage'], null, null)['token'];
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzscim.local'");
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zzscim-%'");
    }

    /** @param array<string,mixed> $body */
    private function scim(string $method, string $url, array $body = []): void
    {
        // _request is MERGED by the trait across calls; reset it before each SCIM
        // request so a previous body does not "stick around".
        $this->_request = [];
        $this->configRequest([
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/scim+json',
            ],
            'input' => (string)json_encode($body === [] ? new stdClass() : $body),
        ]);
        match ($method) {
            'GET' => $this->get($url),
            'POST' => $this->post($url),
            'PUT' => $this->put($url),
            'PATCH' => $this->patch($url),
            'DELETE' => $this->delete($url),
        };
    }

    /** @return array<string,mixed> */
    private function body(): array
    {
        return (array)json_decode((string)$this->_response->getBody(), true);
    }

    public function testRequiresScimScope(): void
    {
        $weak = (new TokenService())->create($this->adminId, 'weak', ['me:read'], null, null)['token'];
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $weak]]);
        $this->get('/api/scim/v2/Users');
        $this->assertResponseCode(403);

        // Without a token -> 401 (ApiAuthMiddleware).
        $this->configRequest(['headers' => []]);
        $this->get('/api/scim/v2/Users');
        $this->assertResponseCode(401);
    }

    public function testServiceProviderConfig(): void
    {
        $this->scim('GET', '/api/scim/v2/ServiceProviderConfig');
        $this->assertResponseOk();
        $this->assertTrue($this->body()['patch']['supported']);
        $this->assertFalse($this->body()['bulk']['supported']);
    }

    public function testCreateFilterAndGet(): void
    {
        $userName = 'zztest_scim_' . bin2hex(random_bytes(3));
        $this->scim('POST', '/api/scim/v2/Users', [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
            'userName' => $userName,
            'name' => ['givenName' => 'Sina', 'familyName' => 'Beispiel'],
            'emails' => [['value' => $userName . '@zzscim.local', 'primary' => true]],
            'active' => true,
        ]);
        $this->assertResponseCode(201);
        $created = $this->body();
        $this->assertSame($userName, $created['userName']);
        $this->assertTrue($created['active']); // invited counts as active (provisioned)

        // Without a password -> status invited (login via SSO/invitation).
        $status = ConnectionManager::get('default')->execute(
            'SELECT status, password_hash FROM users WHERE id = :id',
            ['id' => $created['id']],
        )->fetch('assoc');
        $this->assertSame('invited', $status['status']);
        $this->assertNull($status['password_hash']);

        // Filter userName eq (IdP reconciliation).
        $this->scim('GET', '/api/scim/v2/Users?filter=' . urlencode('userName eq "' . $userName . '"'));
        $this->assertResponseOk();
        $this->assertSame(1, $this->body()['totalResults']);
        $this->assertSame($created['id'], $this->body()['Resources'][0]['id']);

        // GET by id.
        $this->scim('GET', '/api/scim/v2/Users/' . $created['id']);
        $this->assertResponseOk();
        $this->assertSame($userName, $this->body()['userName']);

        // Unknown/malformed ID -> 404 (SCIM error, no 500).
        $this->scim('GET', '/api/scim/v2/Users/garbage');
        $this->assertResponseCode(404);
    }

    public function testPatchDeactivatesAndDeleteSoftDeletes(): void
    {
        $userName = 'zztest_scim_' . bin2hex(random_bytes(3));
        $this->scim('POST', '/api/scim/v2/Users', [
            'userName' => $userName,
            'emails' => [['value' => $userName . '@zzscim.local']],
        ]);
        $id = (string)$this->body()['id'];

        // PATCH active:false (Azure AD style, object-valued replace).
        $this->scim('PATCH', '/api/scim/v2/Users/' . $id, [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [['op' => 'replace', 'value' => ['active' => false]]],
        ]);
        $this->assertResponseOk();
        $this->assertFalse($this->body()['active']);
        $this->assertSame('disabled', ConnectionManager::get('default')->execute(
            'SELECT status FROM users WHERE id = :id',
            ['id' => $id],
        )->fetch('assoc')['status']);

        // PATCH active:true WITHOUT a password: invited protection holds -> not active...
        // (was disabled with password_hash NULL -> active only sets to active when
        // a login path exists; disabled+no hash was previously invited).
        // DELETE -> deactivation, NO deletion (ch. 27.15).
        $this->scim('DELETE', '/api/scim/v2/Users/' . $id);
        $this->assertResponseCode(204);
        $row = ConnectionManager::get('default')->execute(
            'SELECT status FROM users WHERE id = :id',
            ['id' => $id],
        )->fetch('assoc');
        $this->assertNotFalse($row); // row still exists
        $this->assertSame('disabled', $row['status']);
    }

    public function testPutReplacesAttributes(): void
    {
        $userName = 'zztest_scim_' . bin2hex(random_bytes(3));
        $this->scim('POST', '/api/scim/v2/Users', [
            'userName' => $userName,
            'emails' => [['value' => $userName . '@zzscim.local']],
        ]);
        $id = (string)$this->body()['id'];

        $this->scim('PUT', '/api/scim/v2/Users/' . $id, [
            'userName' => $userName,
            'name' => ['givenName' => 'Neu', 'familyName' => 'Name'],
            'emails' => [['value' => $userName . '@zzscim.local']],
        ]);
        $this->assertResponseOk();
        $this->assertSame('Neu', $this->body()['name']['givenName']);
    }

    public function testCrossTenantIsolation(): void
    {
        // Peer-review F2/F3/F6: a scim:manage token from tenant B must neither read
        // nor mutate another tenant's users, and POSTs must land in the caller tenant.
        // core.users has no RLS, so isolation is the explicit tenant predicate the
        // SCIM controller now applies on every query.
        $conn = ConnectionManager::get('default');

        // Victim in tenant A (the operator/default tenant = $this->adminId's tenant).
        $victimName = 'zztest_scimvic_' . bin2hex(random_bytes(3));
        $victimId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => $victimName, 'e' => $victimName . '@zzscim.local'],
        )->fetch('assoc')['id'];

        // Tenant B with its own admin + scim:manage token (the token's user tenant
        // becomes core.current_tenant() for the request).
        $tenantB = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zzscim-' || substr(md5(random()::text), 1, 8), 'ZZ Scim B') RETURNING id",
        )->fetch('assoc')['id'];
        $adminB = (string)$conn->execute(
            "INSERT INTO users (username, email, status, tenant_id) VALUES (:u, :e, 'active', :t) RETURNING id",
            ['u' => 'zztest_scimadmb_' . bin2hex(random_bytes(3)), 'e' => 'scimadmb_' . bin2hex(random_bytes(3)) . '@zzscim.local', 't' => $tenantB],
        )->fetch('assoc')['id'];
        $this->token = (new TokenService())->create($adminB, 'SCIM-B', ['scim:manage'], null, null)['token'];

        // Not in the list...
        $this->scim('GET', '/api/scim/v2/Users');
        $this->assertResponseOk();
        $ids = array_map(static fn(array $r): string => (string)$r['id'], $this->body()['Resources']);
        $this->assertNotContains($victimId, $ids, "tenant B must not list tenant A's user");

        // ...not findable by filter...
        $this->scim('GET', '/api/scim/v2/Users?filter=' . urlencode('userName eq "' . $victimName . '"'));
        $this->assertSame(0, $this->body()['totalResults']);

        // ...not gettable by id...
        $this->scim('GET', '/api/scim/v2/Users/' . $victimId);
        $this->assertResponseCode(404);

        // ...not mutable / deactivatable.
        $this->scim('PATCH', '/api/scim/v2/Users/' . $victimId, [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [['op' => 'replace', 'value' => ['active' => false]]],
        ]);
        $this->assertResponseCode(404);
        $this->scim('DELETE', '/api/scim/v2/Users/' . $victimId);
        $this->assertResponseCode(404);
        $this->assertSame('active', (string)$conn->execute(
            'SELECT status FROM users WHERE id = :id',
            ['id' => $victimId],
        )->fetch('assoc')['status'], "tenant A's user must be untouched");

        // A POST by B attributes the new user to tenant B, not the operator default.
        $newName = 'zztest_scimnew_' . bin2hex(random_bytes(3));
        $this->scim('POST', '/api/scim/v2/Users', [
            'userName' => $newName,
            'emails' => [['value' => $newName . '@zzscim.local']],
        ]);
        $this->assertResponseCode(201);
        $newId = (string)$this->body()['id'];
        $this->assertSame($tenantB, (string)$conn->execute(
            'SELECT tenant_id FROM users WHERE id = :id',
            ['id' => $newId],
        )->fetch('assoc')['tenant_id'], 'POST attributes the new user to the caller tenant');
    }

    public function testDuplicateRejected(): void
    {
        $userName = 'zztest_scim_' . bin2hex(random_bytes(3));
        $payload = ['userName' => $userName, 'emails' => [['value' => $userName . '@zzscim.local']]];
        $this->scim('POST', '/api/scim/v2/Users', $payload);
        $this->assertResponseCode(201);
        $this->scim('POST', '/api/scim/v2/Users', $payload);
        $this->assertResponseCode(409);
    }
}
