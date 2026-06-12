<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Audit\AuditLogger;
use App\Service\Api\TokenService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integration test for the audit export endpoints (point 3b): admin NDJSON
 * download and API stream with scope gate (`audit:read`).
 */
class AuditExportEndpointTest extends TestCase
{
    use IntegrationTestTrait;

    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "INSERT INTO admin_areas (area_key, label, sort_order) VALUES ('core_config', 'Core', 60) "
            . 'ON CONFLICT (area_key) DO NOTHING',
        );
        $this->adminId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_audex_' . bin2hex(random_bytes(3)), 'e' => 'audex_' . bin2hex(random_bytes(3)) . '@zzaudit.local'],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $this->adminId, 'a' => 'core_config'],
        );
        (new AuditLogger())->log('zztest.export', 'zztest_e', '019eb000-0000-7000-8000-000000000009', [
            'newValue' => ['secret' => 'value-snapshot'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        // audit_log is append-only (trigger) -> bypass within a transaction.
        $conn->begin();
        $conn->execute("SET LOCAL app.allow_audit_mutation = 'on'");
        $conn->execute("DELETE FROM audit_log WHERE action LIKE 'zztest.%'");
        $conn->commit();
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzaudit.local'");
    }

    /**
     * Read the NDJSON body of a CallbackStream response: the callback writes via
     * `echo` (memory-friendly streaming under FPM); in the test we capture the
     * output via an output buffer.
     */
    private function streamBody(): string
    {
        ob_start();
        $this->_response->getBody()->getContents();

        return (string)ob_get_clean();
    }

    public function testAdminExportStreamsNdjsonWithValues(): void
    {
        $this->session(['Auth' => ['id' => $this->adminId, 'username' => 'zztest_audex', 'email' => 'a@zzaudit.local']]);
        $this->get('/admin/audit/export?action=zztest.export');

        $this->assertResponseOk();
        $this->assertHeaderContains('Content-Type', 'application/x-ndjson');
        $this->assertHeaderContains('Content-Disposition', 'audit-export-');
        $first = json_decode((string)strtok($this->streamBody(), "\n"), true);
        $this->assertSame('zztest.export', $first['action']);
        // Admin export contains the value snapshots (authorized).
        $this->assertSame(['secret' => 'value-snapshot'], $first['new_value']);
    }

    public function testApiRequiresScopeAndStreams(): void
    {
        // Without the scope -> 403.
        $weak = (new TokenService())->create($this->adminId, 'weak', ['me:read'], null, null)['token'];
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $weak]]);
        $this->get('/api/v1/audit?action=zztest.export');
        $this->assertResponseCode(403);

        // With audit:read -> NDJSON; default without value snapshots (PII-lean).
        $this->_request = [];
        $token = (new TokenService())->create($this->adminId, 'aud', ['audit:read'], null, null)['token'];
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $token]]);
        $this->get('/api/v1/audit?action=zztest.export');
        $this->assertResponseOk();
        $this->assertHeaderContains('Content-Type', 'application/x-ndjson');
        $row = json_decode((string)strtok($this->streamBody(), "\n"), true);
        $this->assertSame('zztest.export', $row['action']);
        $this->assertArrayNotHasKey('new_value', $row); // stream is lean

        // with_values=1 enables the snapshots.
        $this->_request = [];
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $token]]);
        $this->get('/api/v1/audit?action=zztest.export&with_values=1');
        $this->assertResponseOk();
        $row2 = json_decode((string)strtok($this->streamBody(), "\n"), true);
        $this->assertSame(['secret' => 'value-snapshot'], $row2['new_value']);
    }
}
