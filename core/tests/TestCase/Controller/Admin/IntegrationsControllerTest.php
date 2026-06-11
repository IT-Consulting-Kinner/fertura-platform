<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integrationstest der Admin-GUI „Integrationen" (zurückgestellter GUI-Ausbau):
 * rendert für einen Admin mit dem Bereich core_config (prüft Controller +
 * Template + i18n + Bereichs-Scoping).
 */
class IntegrationsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "INSERT INTO admin_areas (area_key, label, sort_order) VALUES ('core_config', 'Core', 60) "
            . 'ON CONFLICT (area_key) DO NOTHING',
        );
        $this->userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_intadmin_' . bin2hex(random_bytes(3)), 'e' => 'intadmin_' . bin2hex(random_bytes(3)) . '@zztest.local'],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $this->userId, 'a' => 'core_config'],
        );
    }

    protected function tearDown(): void
    {
        ConnectionManager::get('default')->execute("DELETE FROM users WHERE email LIKE '%@zztest.local'");
        parent::tearDown();
    }

    public function testIndexRendersForCoreConfigAdmin(): void
    {
        $this->session(['Auth' => [
            'id' => $this->userId,
            'username' => 'zztest_intadmin',
            'email' => 'i@zztest.local',
        ]]);

        $this->get('/admin/integrations');

        $this->assertResponseOk();
        $this->assertResponseContains('Integrations &'); // i18n-Titel (en_US)
        $this->assertResponseContains('Outbound webhooks');
        $this->assertResponseContains('Workflows');
    }

    public function testActionsFailGracefullyForMalformedId(): void
    {
        // Fehlgeformte UUID in der URL: UUID-Guard -> Flash + Redirect statt
        // 22P02 ("invalid input syntax for type uuid") -> 500.
        $this->session(['Auth' => [
            'id' => $this->userId,
            'username' => 'zztest_intadmin',
            'email' => 'i@zztest.local',
        ]]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $actions = [
            'webhookToggle', 'webhookDelete', 'deliveryRetry', 'ssoToggle', 'ssoDelete',
            'automationToggle', 'automationDelete', 'workflowToggle', 'workflowDelete',
        ];
        foreach ($actions as $action) {
            $this->post('/admin/integrations/' . $action . '/garbage');
            $this->assertRedirect(['action' => 'index'], "Aktion $action muss umleiten statt 500");
        }
    }
}
