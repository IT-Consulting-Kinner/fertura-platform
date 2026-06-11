<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Render-Smoke der bislang ungetesteten Admin-Screens: Dashboard, Audit-Log,
 * Backup, Update-Historie, Registry (Contracts + Interfaces), Marketplace
 * (Status + Lizenzen) — echte Route + Template + A11y-Grundgerüst je Screen,
 * plus Fehlerpfade der Backup-Aktionen (unbekannte ID → Flash statt 500).
 */
class AdminScreensSmokeTest extends TestCase
{
    use IntegrationTestTrait;

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
        foreach (self::AREAS as $area) {
            $conn->execute(
                'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
                ['u' => $this->userId, 'a' => $area],
            );
        }
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
        // URL => erwarteter Inhalts-Marker (Screen wirklich gerendert, nicht nur 200).
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

        // Unbekannte Backup-ID -> Flash + Redirect (kein 500), kein Download-Leak.
        $this->post('/admin/backup/verify/zz-no-such-backup');
        $this->assertRedirect(['action' => 'index']);

        $this->post('/admin/backup/delete/zz-no-such-backup');
        $this->assertRedirect(['action' => 'index']);

        $this->get('/admin/backup/download/zz-no-such-backup');
        $this->assertResponseCode(302); // zurück zur Liste statt Datei
    }

    public function testOutboxAndTokenActionsFailGracefullyForMalformedId(): void
    {
        // Fehlgeformte UUID in der URL: UUID-Guard an der Service-Grenze
        // (OutboxAdmin/TokenService) -> not_found-Flash + Redirect statt
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
        // Benutzer OHNE Bereiche: Admin-Screens verweigern (Redirect/403, kein Inhalt).
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
