<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use App\Service\System\AllowTokenCookie;
use App\Test\TestCase\AdminAreaSeedTrait;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integration test for the maintenance GUI (Phase 3): the area gate, engage (creates
 * the session + pauses the workers + issues the cookie), release (resumes + closes),
 * and the JSON status poll. The maintenance session is cleaned around every test —
 * a leaked session would 503 the entire rest of the suite via the selective gate.
 */
class MaintenanceControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use AdminAreaSeedTrait;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "INSERT INTO admin_areas (area_key, label, sort_order) VALUES ('system_maintenance', 'Wartung', 80) "
            . 'ON CONFLICT (area_key) DO NOTHING',
        );
        $this->userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_maint_' . bin2hex(random_bytes(3)), 'e' => 'maint_' . bin2hex(random_bytes(3)) . '@zzmaint.local'],
        )->fetch('assoc')['id'];
        $this->grantAdminAreas($this->userId, 'system_maintenance');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute('DELETE FROM core.critical_action');
        $conn->execute('DELETE FROM core.maintenance_session');
        $conn->execute('UPDATE core.worker_pause SET paused = false, deadline_at = NULL WHERE id = true');
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzmaint.local'");
    }

    private function engageSession(): string
    {
        return (string)ConnectionManager::get('default')->execute(
            'INSERT INTO core.maintenance_session (actor_user_id, allow_token_hash) VALUES (:a, :h) RETURNING id',
            ['a' => $this->userId, 'h' => hash('sha256', 'x')],
        )->fetch('assoc')['id'];
    }

    private function login(?string $userId = null): void
    {
        $this->session(['Auth' => ['id' => $userId ?? $this->userId, 'username' => 'zztest_maint']]);
    }

    public function testRequiresMaintenanceArea(): void
    {
        // A user holding a DIFFERENT area (not system_maintenance) is forbidden.
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "INSERT INTO admin_areas (area_key, label, sort_order) VALUES ('core_config', 'Core', 60) ON CONFLICT DO NOTHING",
        );
        $otherId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_maint_other_' . bin2hex(random_bytes(3)), 'e' => 'other_' . bin2hex(random_bytes(3)) . '@zzmaint.local'],
        )->fetch('assoc')['id'];
        $this->grantAdminAreas($otherId, 'core_config');

        $this->login($otherId);
        $this->get('/admin/maintenance');
        $this->assertResponseCode(403);
    }

    public function testEngageCreatesSessionAndPausesWorkers(): void
    {
        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/admin/maintenance/engage', ['reason' => 'patching']);
        $this->assertRedirect(['action' => 'index']);

        $conn = ConnectionManager::get('default');
        $session = $conn->execute(
            "SELECT actor_user_id, reason FROM core.maintenance_session WHERE status = 'active'",
        )->fetch('assoc');
        $this->assertNotFalse($session);
        $this->assertSame($this->userId, $session['actor_user_id']);
        $this->assertSame('patching', $session['reason']);

        $paused = $conn->execute('SELECT paused FROM core.worker_pause WHERE id = true')->fetch('assoc');
        $this->assertTrue((bool)$paused['paused']);

        // The operator's allow-token cookie is issued on the engage response.
        $cookie = $this->_response->getCookie(AllowTokenCookie::NAME);
        $this->assertNotNull($cookie);
        $this->assertNotEmpty($cookie['value']);
    }

    public function testGateBlocksNonActorThroughFullStack(): void
    {
        // Maintenance owned by SOMEONE ELSE: our admin (holds the area, but is not
        // the actor and has no allow-token) is 503'd by the gate before the
        // controller runs — proves the real AuthenticationMiddleware -> gate chain.
        ConnectionManager::get('default')->execute(
            'INSERT INTO core.maintenance_session (actor_user_id, allow_token_hash) VALUES (:a, :h)',
            ['a' => '99999999-9999-7999-8999-999999999999', 'h' => hash('sha256', 'x')],
        );
        $this->login();
        $this->get('/admin/maintenance');
        $this->assertResponseCode(503);
    }

    public function testLoginPageIsBlockedDuringMaintenance(): void
    {
        // Fail-closed login (decision #2): an anonymous request to the login page
        // during maintenance is 503'd through the full stack, so nobody can sign in.
        ConnectionManager::get('default')->execute(
            'INSERT INTO core.maintenance_session (actor_user_id, allow_token_hash) VALUES (:a, :h)',
            ['a' => '99999999-9999-7999-8999-999999999999', 'h' => hash('sha256', 'x')],
        );
        $this->get('/login');
        $this->assertResponseCode(503);
    }

    public function testReleaseResumesAndCloses(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute(
            'INSERT INTO core.maintenance_session (actor_user_id, allow_token_hash) VALUES (:a, :h)',
            ['a' => $this->userId, 'h' => hash('sha256', 'x')],
        );
        $conn->execute('UPDATE core.worker_pause SET paused = true WHERE id = true');

        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/admin/maintenance/release');
        $this->assertRedirect(['action' => 'index']);

        $open = $conn->execute(
            "SELECT count(*) AS c FROM core.maintenance_session WHERE status <> 'closed'",
        )->fetch('assoc');
        $this->assertSame(0, (int)$open['c']);
        $paused = $conn->execute('SELECT paused FROM core.worker_pause WHERE id = true')->fetch('assoc');
        $this->assertFalse((bool)$paused['paused']);
    }

    public function testStatusReturnsActiveJson(): void
    {
        $this->engageSession();

        $this->login();
        $this->get('/admin/maintenance/status');

        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $this->assertResponseContains('"active":true');
    }

    public function testReleaseBlockedWhileActionInFlight(): void
    {
        $sessionId = $this->engageSession();
        $conn = ConnectionManager::get('default');
        // A fresh, in-flight critical action must block "exit only when stable".
        $conn->execute(
            "INSERT INTO core.critical_action (type, status, maintenance_session_id) VALUES ('test', 'running', :s)",
            ['s' => $sessionId],
        );
        $conn->execute('UPDATE core.worker_pause SET paused = true WHERE id = true');

        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/admin/maintenance/release');
        $this->assertRedirect(['action' => 'index']);

        // Still in maintenance — release was refused.
        $open = (int)$conn->execute(
            "SELECT count(*) AS c FROM core.maintenance_session WHERE status <> 'closed'",
        )->fetch('assoc')['c'];
        $this->assertSame(1, $open);
        // Workers must stay paused on the refused path (no premature resume).
        $paused = $conn->execute('SELECT paused FROM core.worker_pause WHERE id = true')->fetch('assoc');
        $this->assertTrue((bool)$paused['paused']);
    }

    public function testReleaseRefusedWhileRecoveredRestoreUnacknowledged(): void
    {
        $sessionId = $this->engageSession();
        $conn = ConnectionManager::get('default');
        $conn->execute('UPDATE core.worker_pause SET paused = true WHERE id = true');
        // A crashed mutating action is swept to needs_manual_restore on the release
        // attempt — and now HOLDS the exit ("Flag HALTEN") until it is acknowledged,
        // so a crash no longer silently brings the platform back on an uncertain DB.
        $conn->execute(
            'INSERT INTO core.critical_action (type, status, maintenance_session_id, heartbeat_at) '
            . "VALUES ('test', 'running', :s, now() - interval '2000 seconds')",
            ['s' => $sessionId],
        );

        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/admin/maintenance/release');
        $this->assertRedirect(['action' => 'index']);

        // Swept to needs_manual_restore...
        $recovered = (int)$conn->execute(
            "SELECT count(*) AS c FROM core.critical_action WHERE status = 'needs_manual_restore'",
        )->fetch('assoc')['c'];
        $this->assertSame(1, $recovered);
        // ...and the unacknowledged restore refused the exit (session stays open).
        $open = (int)$conn->execute(
            "SELECT count(*) AS c FROM core.maintenance_session WHERE status <> 'closed'",
        )->fetch('assoc')['c'];
        $this->assertSame(1, $open);
        $paused = $conn->execute('SELECT paused FROM core.worker_pause WHERE id = true')->fetch('assoc');
        $this->assertTrue((bool)$paused['paused']); // no premature resume
    }

    public function testReleaseSucceedsAfterRestoreAcknowledged(): void
    {
        $sessionId = $this->engageSession();
        $conn = ConnectionManager::get('default');
        $conn->execute('UPDATE core.worker_pause SET paused = true WHERE id = true');
        // An ALREADY-acknowledged needs_manual_restore no longer holds the exit.
        $conn->execute(
            'INSERT INTO core.critical_action (type, status, maintenance_session_id, acknowledged_at) '
            . "VALUES ('test', 'needs_manual_restore', :s, now())",
            ['s' => $sessionId],
        );

        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/admin/maintenance/release');
        $this->assertRedirect(['action' => 'index']);

        $open = (int)$conn->execute(
            "SELECT count(*) AS c FROM core.maintenance_session WHERE status <> 'closed'",
        )->fetch('assoc')['c'];
        $this->assertSame(0, $open);
        $paused = $conn->execute('SELECT paused FROM core.worker_pause WHERE id = true')->fetch('assoc');
        $this->assertFalse((bool)$paused['paused']);
    }

    public function testAcknowledgeRestoreStampsActorAndPreservesState(): void
    {
        $sessionId = $this->engageSession();
        $conn = ConnectionManager::get('default');
        $actionId = (string)$conn->execute(
            'INSERT INTO core.critical_action (type, status, maintenance_session_id) '
            . "VALUES ('test', 'needs_manual_restore', :s) RETURNING id",
            ['s' => $sessionId],
        )->fetch('assoc')['id'];

        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/admin/maintenance/acknowledge-restore', [
            'action_id' => $actionId,
            'note' => 'restored from pre-action backup',
        ]);
        $this->assertRedirect(['action' => 'index']);

        $row = $conn->execute(
            'SELECT status, acknowledged_at, acknowledged_by FROM core.critical_action WHERE id = :id',
            ['id' => $actionId],
        )->fetch('assoc');
        // State stays needs_manual_restore (revision-proof); acknowledgement recorded.
        $this->assertSame('needs_manual_restore', $row['status']);
        $this->assertNotNull($row['acknowledged_at']);
        $this->assertSame($this->userId, $row['acknowledged_by']);
    }

    public function testAcknowledgeRestoreRejectsMalformedActionId(): void
    {
        $this->engageSession();
        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        // A non-UUID id must fail cleanly (redirect + flash), not raise a 500 when the
        // raw UPDATE binds it to the uuid id column (PG 22P02).
        $this->post('/admin/maintenance/acknowledge-restore', ['action_id' => 'not-a-uuid']);
        $this->assertRedirect(['action' => 'index']);
    }

    public function testStatusReportsNeedsAck(): void
    {
        $sessionId = $this->engageSession();
        ConnectionManager::get('default')->execute(
            "INSERT INTO core.critical_action (type, status, maintenance_session_id) VALUES ('test', 'needs_manual_restore', :s)",
            ['s' => $sessionId],
        );

        $this->login();
        $this->get('/admin/maintenance/status');

        $this->assertResponseOk();
        $this->assertResponseContains('"needs_ack":1');
        $this->assertResponseContains('"can_release":false');
    }

    public function testProtectedInstallRequiresMaintenance(): void
    {
        // No active session -> the protected install is refused, nothing is enqueued.
        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/admin/maintenance/installModule', []);
        $this->assertRedirect(['action' => 'index']);

        $count = (int)ConnectionManager::get('default')
            ->execute('SELECT count(*) AS c FROM core.critical_action')->fetch('assoc')['c'];
        $this->assertSame(0, $count);
    }

    public function testStatusReportsBlockingActions(): void
    {
        $sessionId = $this->engageSession();
        ConnectionManager::get('default')->execute(
            "INSERT INTO core.critical_action (type, status, maintenance_session_id) VALUES ('test', 'running', :s)",
            ['s' => $sessionId],
        );

        $this->login();
        $this->get('/admin/maintenance/status');

        $this->assertResponseOk();
        $this->assertResponseContains('"blocking_actions":1');
        $this->assertResponseContains('"can_release":false');
    }
}
