<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use App\Test\TestCase\AdminAreaSeedTrait;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integration test for the "Integrations" admin GUI (deferred GUI build-out):
 * renders for an admin with the core_config area (verifies controller +
 * template + i18n + area scoping).
 */
class IntegrationsControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use AdminAreaSeedTrait;

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
        $this->grantAdminAreas($this->userId, 'core_config');
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
        $this->assertResponseContains('Integrations &'); // i18n title (en_US)
        $this->assertResponseContains('Outbound webhooks');
        $this->assertResponseContains('Workflows');
    }

    public function testActionsFailGracefullyForMalformedId(): void
    {
        // Malformed UUID in the URL: UUID guard -> flash + redirect instead of
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

        // Invalid URL (not http/https) -> error flash, no record.
        $bad = 'zztest-wh-bad-' . bin2hex(random_bytes(2));
        $this->post('/admin/integrations/webhookCreate', ['name' => $bad, 'url' => 'ftp://nope']);
        $this->assertRedirect(['action' => 'index']);
        $this->assertFalse(ConnectionManager::get('default')->execute(
            'SELECT 1 FROM webhook_subscriptions WHERE name = :n',
            ['n' => $bad],
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
        // Client secret stored encrypted — never in plaintext.
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
            "SELECT 1 FROM sso_providers WHERE name = :n AND type = 'saml'",
            ['n' => $name],
        )->fetch());

        // Missing required fields (SAML without certificate) -> no record.
        $bad = 'zztest-saml-bad-' . bin2hex(random_bytes(2));
        $this->post('/admin/integrations/ssoCreate', [
            'type' => 'saml', 'name' => $bad, 'idp_entity_id' => 'x', 'idp_sso_url' => 'y',
        ]);
        $this->assertFalse(ConnectionManager::get('default')->execute(
            'SELECT 1 FROM sso_providers WHERE name = :n',
            ['n' => $bad],
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

        // Actions not a JSON array (object) -> rejected, no record.
        $bad = 'zztest-rule-bad-' . bin2hex(random_bytes(2));
        $this->post('/admin/integrations/automationCreate', [
            'name' => $bad, 'event' => 'x', 'actions' => '{"not":"a-list"}',
        ]);
        $this->assertFalse(ConnectionManager::get('default')->execute(
            'SELECT 1 FROM automation_rules WHERE name = :n',
            ['n' => $bad],
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
        $this->assertSame('entity_id', $row['entity_id_field']); // default applies
        $this->assertSame('open', $row['initial_state']);

        // Missing initial state -> no record.
        $bad = 'zztest-wf-bad-' . bin2hex(random_bytes(2));
        $this->post('/admin/integrations/workflowCreate', ['name' => $bad, 'entity_type' => 'ticket']);
        $this->assertFalse(ConnectionManager::get('default')->execute(
            'SELECT 1 FROM workflow_definitions WHERE name = :n',
            ['n' => $bad],
        )->fetch());
    }
}
