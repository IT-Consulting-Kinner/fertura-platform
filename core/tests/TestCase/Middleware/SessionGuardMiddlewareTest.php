<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Test der Session-Anomalie-Erkennung (SessionGuardMiddleware): UA-Bindung
 * (Wechsel = gestohlenes Cookie -> Session-Ende), unveränderter UA passiert,
 * neues Gerät erzeugt eine In-App-Benachrichtigung (das ERSTE Gerät — die
 * Einrichtung selbst — bewusst nicht).
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
        // Vom „neues Gerät"-Hinweis erzeugte Outbox-Events räumen, sonst
        // verfälschen sie die globale Outbox-Fairness (DEFAULT-Mandanten-Partition).
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
        // Session wurde mit UA "Browser-A" gebunden; Request kommt mit "Browser-B".
        $this->loginAs('Browser-B', [
            'Guard' => ['ua' => hash('sha256', 'Browser-A'), 'ip' => ''],
        ]);
        $this->get('/mfa');

        $this->assertRedirectContains('/login'); // Session zerstört, fail-closed
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
        // Erster authentifizierter Request (kein Guard in der Session): Gerät A
        // wird registriert — KEINE Benachrichtigung (Einrichtung selbst).
        $this->loginAs('Device-A');
        $this->get('/mfa');
        $this->assertResponseOk();
        $count = fn(): int => (int)ConnectionManager::get('default')->execute(
            "SELECT count(*) AS c FROM notifications WHERE user_id = :u AND type = 'security.new_device'",
            ['u' => $this->userId],
        )->fetch('assoc')['c'];
        $this->assertSame(0, $count());

        // Neue Session von Gerät B (frische Session ohne Guard): Hinweis.
        $this->loginAs('Device-B');
        $this->get('/mfa');
        $this->assertResponseOk();
        $this->assertSame(1, $count());

        // Gerät B erneut (wieder frische Session): bekannt -> kein weiterer Hinweis.
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
