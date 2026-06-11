<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integrationstest der Trust-GUI (Vertrauensanker & Sperrliste, Kap. 24.9.2):
 * Anzeige, Widerruf (→ Sperrliste) und manuelles Hinzufügen eines Ankers.
 */
class TrustControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "INSERT INTO admin_areas (area_key, label, sort_order) VALUES ('core_config', 'Core', 60) "
            . 'ON CONFLICT (area_key) DO NOTHING',
        );
        $this->userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_tradmin_' . bin2hex(random_bytes(3)), 'e' => 'tradmin_' . bin2hex(random_bytes(3)) . '@zztrust.local'],
        )->fetch('assoc')['id'];
        $conn->execute('INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)', ['u' => $this->userId, 'a' => 'core_config']);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute("DELETE FROM trust_anchors WHERE key_id LIKE 'zztest-%'");
        $conn->execute("DELETE FROM revoked_keys WHERE key_id LIKE 'zztest-%'");
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zztrust.local'");
    }

    private function login(): void
    {
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_tradmin', 'email' => 't@zztrust.local']]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
    }

    public function testIndexRendersAnchors(): void
    {
        ConnectionManager::get('default')->execute(
            "INSERT INTO trust_anchors (key_id, public_key, key_type) VALUES ('zztest-root', 'pk', 'root')",
        );
        $this->login();
        $this->get('/admin/trust');

        $this->assertResponseOk();
        $this->assertResponseContains('zztest-root');
        $this->assertResponseContains('scope="col"'); // A11y der Tabelle
    }

    public function testRevokeAddsToRevocationList(): void
    {
        ConnectionManager::get('default')->execute(
            "INSERT INTO trust_anchors (key_id, public_key, key_type) VALUES ('zztest-pub', 'pk', 'publisher')",
        );
        $this->login();
        $this->post('/admin/trust/revoke/zztest-pub', ['reason' => 'kompromittiert']);

        $this->assertRedirect(['action' => 'index']);
        $this->assertNotFalse(ConnectionManager::get('default')->execute(
            "SELECT 1 FROM revoked_keys WHERE key_id = 'zztest-pub'",
        )->fetch());
    }

    public function testAddAnchorCreatesRow(): void
    {
        $this->login();
        $this->post('/admin/trust/addAnchor', [
            'key_id' => 'zztest-added', 'public_key' => 'base64key', 'key_type' => 'root', 'publisher' => 'ACME',
        ]);
        $this->assertRedirect(['action' => 'index']);
        $row = ConnectionManager::get('default')->execute(
            "SELECT key_type, publisher FROM trust_anchors WHERE key_id = 'zztest-added'",
        )->fetch('assoc');
        $this->assertNotFalse($row);
        $this->assertSame('root', $row['key_type']);

        // Pflichtfeld fehlt (kein public_key) -> kein Datensatz.
        $this->post('/admin/trust/addAnchor', ['key_id' => 'zztest-incomplete', 'key_type' => 'root']);
        $this->assertFalse(ConnectionManager::get('default')->execute(
            "SELECT 1 FROM trust_anchors WHERE key_id = 'zztest-incomplete'",
        )->fetch());
    }
}
