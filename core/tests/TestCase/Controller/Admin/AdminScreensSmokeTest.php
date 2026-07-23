<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use App\Test\TestCase\AdminAreaSeedTrait;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Render smoke test for the previously untested admin screens: Dashboard, audit
 * log, backup, update history, registry (contracts + interfaces), marketplace
 * (status + licenses) — real route + template + A11y scaffolding per screen,
 * plus the error paths of the backup actions (unknown ID → flash instead of 500).
 */
class AdminScreensSmokeTest extends TestCase
{
    use IntegrationTestTrait;
    use AdminAreaSeedTrait;

    private string $userId;

    private const AREAS = ['core_config', 'update_manager', 'registry_contracts', 'marketplace_license'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        foreach (self::AREAS as $i => $area) {
            $conn->execute(
                'INSERT INTO admin_areas (area_key, label, sort_order) VALUES (:a, :a, :s) '
                . 'ON CONFLICT (area_key) DO NOTHING',
                ['a' => $area, 's' => 90 + $i],
            );
        }
        $this->userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_sadmin_' . bin2hex(random_bytes(3)), 'e' => 'sadmin_' . bin2hex(random_bytes(3)) . '@zzsmoke.local'],
        )->fetch('assoc')['id'];
        $this->grantAdminAreas($this->userId, ...self::AREAS);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        ConnectionManager::get('default')->execute("DELETE FROM users WHERE email LIKE '%@zzsmoke.local'");
    }

    private function login(): void
    {
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_sadmin', 'email' => 's@zzsmoke.local']]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
    }

    public function testCoreAdminScreensRender(): void
    {
        $this->login();
        // URL => expected content marker (screen actually rendered, not just 200).
        $screens = [
            '/admin' => 'id="main"',
            '/admin/audit' => 'scope="col"',
            '/admin/backup' => 'scope="col"',
            '/admin/updates' => 'scope="col"',
            '/admin/registry' => 'scope="col"',
            '/admin/registry/interfaces' => 'scope="col"',
            '/admin/marketplace' => 'id="main"',
            '/admin/marketplace/licenses' => 'scope="col"',
        ];
        foreach ($screens as $url => $marker) {
            $this->get($url);
            $this->assertResponseOk("Screen $url nicht OK");
            $this->assertResponseContains($marker, "Marker '$marker' fehlt auf $url");
        }
    }

    public function testBackupActionsFailGracefullyForUnknownId(): void
    {
        $this->login();

        // Unknown backup ID -> flash + redirect (no 500), no download leak.
        $this->post('/admin/backup/verify/zz-no-such-backup');
        $this->assertRedirect(['action' => 'index']);

        $this->post('/admin/backup/delete/zz-no-such-backup');
        $this->assertRedirect(['action' => 'index']);

        $this->get('/admin/backup/download/zz-no-such-backup');
        $this->assertResponseCode(302); // back to the list instead of a file

        // Off-site disabled by default -> upload action refuses (no 500).
        $this->post('/admin/backup/offsiteUpload/00000000-0000-0000-0000-000000000000');
        $this->assertRedirect(['action' => 'index']);
        $this->post('/admin/backup/offsiteDelete/whatever.zip');
        $this->assertRedirect(['action' => 'index']);
    }

    public function testOutboxAndTokenActionsFailGracefullyForMalformedId(): void
    {
        // Malformed UUID in the URL: UUID guard at the service boundary
        // (OutboxAdmin/TokenService) -> not_found flash + redirect instead of
        // 22P02 ("invalid input syntax for type uuid") -> 500.
        $this->login();

        $this->post('/admin/outbox/retry/garbage');
        $this->assertRedirect(['action' => 'index']);

        $this->post('/admin/outbox/discard/garbage');
        $this->assertRedirect(['action' => 'index']);

        $this->post('/admin/tokens/revoke/garbage');
        $this->assertRedirect(['action' => 'index']);
    }

    public function testAdminScreensRequireTheirArea(): void
    {
        // User WITHOUT areas: admin screens are denied (redirect/403, no content).
        $conn = ConnectionManager::get('default');
        $plain = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_noarea_' . bin2hex(random_bytes(3)), 'e' => 'noarea_' . bin2hex(random_bytes(3)) . '@zzsmoke.local'],
        )->fetch('assoc')['id'];
        $this->session(['Auth' => ['id' => $plain, 'username' => 'zztest_noarea', 'email' => 'n@zzsmoke.local']]);

        $this->get('/admin/backup');
        $this->assertTrue(
            $this->_response->getStatusCode() === 403 || $this->_response->hasHeader('Location'),
            'Backup-Screen ohne Bereich muss verweigert werden',
        );
    }
}
