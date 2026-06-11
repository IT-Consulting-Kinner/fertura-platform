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
        $conn = ConnectionManager::get('default');
        $conn->execute("DELETE FROM webhook_subscriptions WHERE name LIKE 'zztest-%'");
        $conn->execute("DELETE FROM sso_providers WHERE name LIKE 'zztest-%'");
        $conn->execute("DELETE FROM automation_rules WHERE name LIKE 'zztest-%'");
        $conn->execute("DELETE FROM workflow_definitions WHERE name LIKE 'zztest-%'");
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zztest.local'");
        parent::tearDown();
    }

    private function login(): void
    {
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_intadmin', 'email' => 'i@zztest.local']]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
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

    public function testWebhookCreateViaGui(): void
    {
        $this->login();
        $name = 'zztest-wh-' . bin2hex(random_bytes(2));
        $this->post('/admin/integrations/webhookCreate', [
            'name' => $name, 'url' => 'https://hook.example/in', 'event_filter' => 'user.*', 'secret' => 's3cr3t',
        ]);
        $this->assertRedirect(['action' => 'index']);
        $row = ConnectionManager::get('default')->execute(
            'SELECT url, event_filter, secret FROM webhook_subscriptions WHERE name = :n',
            ['n' => $name],
        )->fetch('assoc');
        $this->assertNotFalse($row);
        $this->assertSame('https://hook.example/in', $row['url']);
        $this->assertSame('user.*', $row['event_filter']);

        // Ungültige URL (kein http/https) -> Fehler-Flash, kein Datensatz.
        $bad = 'zztest-wh-bad-' . bin2hex(random_bytes(2));
        $this->post('/admin/integrations/webhookCreate', ['name' => $bad, 'url' => 'ftp://nope']);
        $this->assertRedirect(['action' => 'index']);
        $this->assertFalse(ConnectionManager::get('default')->execute(
            'SELECT 1 FROM webhook_subscriptions WHERE name = :n', ['n' => $bad],
        )->fetch());
    }

    public function testSsoCreateOidcEncryptsSecret(): void
    {
        $this->login();
        $name = 'zztest-oidc-' . bin2hex(random_bytes(2));
        $this->post('/admin/integrations/ssoCreate', [
            'type' => 'oidc', 'name' => $name, 'button_label' => 'Login via IdP',
            'issuer' => 'https://idp.example/', 'client_id' => 'abc', 'client_secret' => 'topsecret',
            'scopes' => 'openid email',
        ]);
        $this->assertRedirect(['action' => 'index']);
        $row = ConnectionManager::get('default')->execute(
            'SELECT type, config, secret_encrypted FROM sso_providers WHERE name = :n',
            ['n' => $name],
        )->fetch('assoc');
        $this->assertNotFalse($row);
        $this->assertSame('oidc', $row['type']);
        $this->assertStringContainsString('idp.example', (string)$row['config']);
        // Client-Secret verschlüsselt abgelegt — nie im Klartext.
        $this->assertNotNull($row['secret_encrypted']);
        $this->assertStringNotContainsString('topsecret', (string)$row['secret_encrypted']);
    }

    public function testSsoCreateSamlAndValidation(): void
    {
        $this->login();
        $name = 'zztest-saml-' . bin2hex(random_bytes(2));
        $this->post('/admin/integrations/ssoCreate', [
            'type' => 'saml', 'name' => $name,
            'idp_entity_id' => 'https://idp.example/meta', 'idp_sso_url' => 'https://idp.example/sso',
            'idp_x509cert' => '-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----',
        ]);
        $this->assertRedirect(['action' => 'index']);
        $this->assertNotFalse(ConnectionManager::get('default')->execute(
            "SELECT 1 FROM sso_providers WHERE name = :n AND type = 'saml'", ['n' => $name],
        )->fetch());

        // Fehlende Pflichtfelder (SAML ohne Zertifikat) -> kein Datensatz.
        $bad = 'zztest-saml-bad-' . bin2hex(random_bytes(2));
        $this->post('/admin/integrations/ssoCreate', [
            'type' => 'saml', 'name' => $bad, 'idp_entity_id' => 'x', 'idp_sso_url' => 'y',
        ]);
        $this->assertFalse(ConnectionManager::get('default')->execute(
            'SELECT 1 FROM sso_providers WHERE name = :n', ['n' => $bad],
        )->fetch());
    }

    public function testAutomationCreateWithJsonValidation(): void
    {
        $this->login();
        $name = 'zztest-rule-' . bin2hex(random_bytes(2));
        $this->post('/admin/integrations/automationCreate', [
            'name' => $name, 'event' => 'ticket.created',
            'condition' => '{"field":"data.priority","op":"eq","value":"high"}',
            'actions' => '[{"type":"notify","user_field":"user_id","title":"X"}]',
        ]);
        $this->assertRedirect(['action' => 'index']);
        $row = ConnectionManager::get('default')->execute(
            'SELECT event, condition, actions FROM automation_rules WHERE name = :n',
            ['n' => $name],
        )->fetch('assoc');
        $this->assertNotFalse($row);
        $this->assertSame('ticket.created', $row['event']);
        $this->assertStringContainsString('priority', (string)$row['condition']);

        // Aktionen kein JSON-Array (Objekt) -> abgelehnt, kein Datensatz.
        $bad = 'zztest-rule-bad-' . bin2hex(random_bytes(2));
        $this->post('/admin/integrations/automationCreate', [
            'name' => $bad, 'event' => 'x', 'actions' => '{"not":"a-list"}',
        ]);
        $this->assertFalse(ConnectionManager::get('default')->execute(
            'SELECT 1 FROM automation_rules WHERE name = :n', ['n' => $bad],
        )->fetch());
    }

    public function testWorkflowCreateWithDefaults(): void
    {
        $this->login();
        $name = 'zztest-wf-' . bin2hex(random_bytes(2));
        $this->post('/admin/integrations/workflowCreate', [
            'name' => $name, 'entity_type' => 'ticket', 'initial_state' => 'open',
            'transitions' => '[{"from":"open","to":"closed","on":"close"}]',
        ]);
        $this->assertRedirect(['action' => 'index']);
        $row = ConnectionManager::get('default')->execute(
            'SELECT entity_type, entity_id_field, initial_state FROM workflow_definitions WHERE name = :n',
            ['n' => $name],
        )->fetch('assoc');
        $this->assertNotFalse($row);
        $this->assertSame('ticket', $row['entity_type']);
        $this->assertSame('entity_id', $row['entity_id_field']); // Default greift
        $this->assertSame('open', $row['initial_state']);

        // Fehlender Startzustand -> kein Datensatz.
        $bad = 'zztest-wf-bad-' . bin2hex(random_bytes(2));
        $this->post('/admin/integrations/workflowCreate', ['name' => $bad, 'entity_type' => 'ticket']);
        $this->assertFalse(ConnectionManager::get('default')->execute(
            'SELECT 1 FROM workflow_definitions WHERE name = :n', ['n' => $bad],
        )->fetch());
    }
}
