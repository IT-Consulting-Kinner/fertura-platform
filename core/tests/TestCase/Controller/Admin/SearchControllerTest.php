<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use App\Service\Search\SearchService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integrationstest der globalen Admin-Suche: rendert die Hybrid-Suche (hier ohne
 * Embedding-Provider → Volltext) für einen Admin und zeigt Treffer (UI-Kit).
 */
class SearchControllerTest extends TestCase
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
            ['u' => 'zztest_sadmin_' . bin2hex(random_bytes(3)), 'e' => 'sadmin_' . bin2hex(random_bytes(3)) . '@zzsearch.local'],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $this->userId, 'a' => 'core_config'],
        );
        // Öffentliches (owner=null) Dokument mit eindeutigem Begriff.
        (new SearchService())->index('zzsearch', 'doc', 's1', 'Quartalszauberwort Bericht', 'Inhalt', null);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzsearch.local'");
        $conn->execute("DELETE FROM search_index WHERE source = 'zzsearch'");
    }

    public function testSearchRendersResultsForAdmin(): void
    {
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_sadmin', 'email' => 's@zzsearch.local']]);

        $this->get('/admin/search?q=' . urlencode('Quartalszauberwort'));

        $this->assertResponseOk();
        $this->assertResponseContains('Quartalszauberwort Bericht'); // Treffer-Titel via UI-Kit
    }

    public function testEmptyQueryRendersForm(): void
    {
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_sadmin', 'email' => 's@zzsearch.local']]);
        $this->get('/admin/search');
        $this->assertResponseOk();
    }
}
