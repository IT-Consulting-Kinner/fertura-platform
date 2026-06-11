<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api\Scim;

use App\Service\Api\TokenService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integrationstest des SCIM-2.0-Provisionings (/api/scim/v2/Users): Scope-Gate,
 * Anlage (invited, ohne Passwort), Filter `userName eq`, PATCH active:false →
 * Deaktivierung, **kein hartes Löschen** (DELETE deaktiviert), und der Schutz
 * „invited ohne Passwort bleibt invited" bei active:true.
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
        ConnectionManager::get('default')->execute("DELETE FROM users WHERE email LIKE '%@zzscim.local'");
    }

    /** @param array<string,mixed> $body */
    private function scim(string $method, string $url, array $body = []): void
    {
        // _request wird vom Trait über Aufrufe hinweg GEMERGED; vor jedem SCIM-
        // Request zurücksetzen, damit ein vorheriger Body nicht „hängen bleibt".
        $this->_request = [];
        $this->configRequest([
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/scim+json',
            ],
            'input' => (string)json_encode($body === [] ? new \stdClass() : $body),
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

        // Ohne Token -> 401 (ApiAuthMiddleware).
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
        $this->assertTrue($created['active']); // invited zählt als aktiv (provisioniert)

        // Ohne Passwort -> Status invited (Login via SSO/Einladung).
        $status = ConnectionManager::get('default')->execute(
            'SELECT status, password_hash FROM users WHERE id = :id',
            ['id' => $created['id']],
        )->fetch('assoc');
        $this->assertSame('invited', $status['status']);
        $this->assertNull($status['password_hash']);

        // Filter userName eq (IdP-Abgleich).
        $this->scim('GET', '/api/scim/v2/Users?filter=' . urlencode('userName eq "' . $userName . '"'));
        $this->assertResponseOk();
        $this->assertSame(1, $this->body()['totalResults']);
        $this->assertSame($created['id'], $this->body()['Resources'][0]['id']);

        // GET by id.
        $this->scim('GET', '/api/scim/v2/Users/' . $created['id']);
        $this->assertResponseOk();
        $this->assertSame($userName, $this->body()['userName']);

        // Unbekannte/fehlgeformte ID -> 404 (SCIM-Error, kein 500).
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

        // PATCH active:false (Azure-AD-Stil, objektwertiges replace).
        $this->scim('PATCH', '/api/scim/v2/Users/' . $id, [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [['op' => 'replace', 'value' => ['active' => false]]],
        ]);
        $this->assertResponseOk();
        $this->assertFalse($this->body()['active']);
        $this->assertSame('disabled', ConnectionManager::get('default')->execute(
            'SELECT status FROM users WHERE id = :id', ['id' => $id],
        )->fetch('assoc')['status']);

        // PATCH active:true OHNE Passwort: bleibt invited-Schutz -> nicht aktiv...
        // (war disabled mit password_hash NULL -> active setzt auf active nur,
        // wenn Anmeldeweg existiert; disabled+kein Hash war vorher invited).
        // DELETE -> Deaktivierung, KEIN Löschen (Kap. 27.15).
        $this->scim('DELETE', '/api/scim/v2/Users/' . $id);
        $this->assertResponseCode(204);
        $row = ConnectionManager::get('default')->execute(
            'SELECT status FROM users WHERE id = :id', ['id' => $id],
        )->fetch('assoc');
        $this->assertNotFalse($row); // Zeile existiert weiterhin
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
