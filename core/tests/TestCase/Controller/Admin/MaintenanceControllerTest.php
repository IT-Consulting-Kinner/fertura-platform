<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use App\Service\System\AllowTokenCookie;
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
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $this->userId, 'a' => 'system_maintenance'],
        );
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
        $conn->execute(
            "INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, 'core_config')",
            ['u' => $otherId],
        );

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

    public function testReleaseRecoversStaleActionThenSucceeds(): void
    {
        $sessionId = $this->engageSession();
        $conn = ConnectionManager::get('default');
        // A crashed action (stale beyond the maintenance recovery window) must NOT
        // deadlock the exit: release recovers it first, then closes the session.
        $conn->execute(
            'INSERT INTO core.critical_action (type, status, maintenance_session_id, heartbeat_at) '
            . "VALUES ('test', 'running', :s, now() - interval '1000 seconds')",
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
        // The crashed action was swept to a terminal recovery state.
        $recovered = (int)$conn->execute(
            "SELECT count(*) AS c FROM core.critical_action WHERE status = 'needs_manual_restore'",
        )->fetch('assoc')['c'];
        $this->assertSame(1, $recovered);
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
