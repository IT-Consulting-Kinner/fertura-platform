<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Tests session anomaly detection (SessionGuardMiddleware): user-agent binding
 * (a change means a stolen cookie -> session ends), an unchanged UA passes, and a
 * new device triggers an in-app notification (the FIRST device — the setup
 * itself — deliberately does not).
 */
class SessionGuardMiddlewareTest extends TestCase
{
    use IntegrationTestTrait;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->userId = (string)ConnectionManager::get('default')->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_guard_' . bin2hex(random_bytes(4)), 'e' => 'guard_' . bin2hex(random_bytes(4)) . '@zzguard.local'],
        )->fetch('assoc')['id'];
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        // Clean up outbox events produced by the "new device" notice; otherwise
        // they skew global outbox fairness (DEFAULT tenant partition).
        if (isset($this->userId)) {
            $conn->execute(
                "DELETE FROM event_outbox WHERE contract_name = 'core.notification.created' "
                . 'AND payload::text LIKE :p',
                ['p' => '%' . $this->userId . '%'],
            );
        }
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzguard.local'");
    }

    private function loginAs(string $ua, array $extraSession = []): void
    {
        $this->configRequest(['headers' => ['User-Agent' => $ua]]);
        $this->session($extraSession + [
            'Auth' => ['id' => $this->userId, 'username' => 'zztest_guard', 'email' => 'g@zzguard.local'],
        ]);
    }

    public function testUaMismatchDestroysSession(): void
    {
        // Session was bound to UA "Browser-A"; the request arrives with "Browser-B".
        $this->loginAs('Browser-B', [
            'Guard' => ['ua' => hash('sha256', 'Browser-A'), 'ip' => ''],
        ]);
        $this->get('/mfa');

        $this->assertRedirectContains('/login'); // session destroyed, fail-closed
        $anomalies = (int)ConnectionManager::get('default')->execute(
            "SELECT count(*) AS c FROM audit_log WHERE action = 'session.anomaly' AND entity_id = :u",
            ['u' => $this->userId],
        )->fetch('assoc')['c'];
        $this->assertGreaterThanOrEqual(1, $anomalies);
    }

    public function testMatchingUaPasses(): void
    {
        $this->loginAs('Browser-A', [
            'Guard' => ['ua' => hash('sha256', 'Browser-A'), 'ip' => ''],
        ]);
        $this->get('/mfa');
        $this->assertResponseOk();
    }

    public function testFirstDeviceSilentSecondDeviceNotifies(): void
    {
        // First authenticated request (no Guard in the session): device A is
        // registered — NO notification (the setup itself).
        $this->loginAs('Device-A');
        $this->get('/mfa');
        $this->assertResponseOk();
        $count = fn(): int => (int)ConnectionManager::get('default')->execute(
            "SELECT count(*) AS c FROM notifications WHERE user_id = :u AND type = 'security.new_device'",
            ['u' => $this->userId],
        )->fetch('assoc')['c'];
        $this->assertSame(0, $count());

        // New session from device B (fresh session without Guard): notice.
        $this->loginAs('Device-B');
        $this->get('/mfa');
        $this->assertResponseOk();
        $this->assertSame(1, $count());

        // Device B again (another fresh session): known -> no further notice.
        $this->loginAs('Device-B');
        $this->get('/mfa');
        $this->assertSame(1, $count());

        $devices = (int)ConnectionManager::get('default')->execute(
            'SELECT count(*) AS c FROM user_known_devices WHERE user_id = :u',
            ['u' => $this->userId],
        )->fetch('assoc')['c'];
        $this->assertSame(2, $devices);
    }
}
