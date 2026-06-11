<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Identity;

use App\Service\Identity\PasswordResetService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Test des Einladungs-/Reset-Token-Lebenszyklus (Kap. 27.2/27.15): Token nur als
 * Hash gespeichert, Einlösung setzt Passwort + aktiviert „invited", verbraucht
 * den Token (und weitere offene), und lehnt zu kurze/ungültige/abgelaufene/
 * bereits verwendete Token ab.
 */
class PasswordResetServiceTest extends TestCase
{
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->userId = (string)ConnectionManager::get('default')->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'invited') RETURNING id",
            ['u' => 'zztest_pr_' . bin2hex(random_bytes(4)), 'e' => 'pr_' . bin2hex(random_bytes(4)) . '@zzpr.local'],
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
        $conn->execute(
            "DELETE FROM password_reset_tokens WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@zzpr.local')",
        );
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzpr.local'");
    }

    private function userStatus(): string
    {
        return (string)ConnectionManager::get('default')
            ->execute('SELECT status FROM users WHERE id = :id', ['id' => $this->userId])
            ->fetch('assoc')['status'];
    }

    public function testCreateStoresOnlyHash(): void
    {
        $svc = new PasswordResetService();
        $token = $svc->create($this->userId, 'invite');
        $this->assertNotSame('', $token);

        $row = ConnectionManager::get('default')->execute(
            'SELECT token_hash FROM password_reset_tokens WHERE user_id = :u',
            ['u' => $this->userId],
        )->fetch('assoc');
        $this->assertSame(hash('sha256', $token), $row['token_hash']);
        $this->assertNotSame($token, $row['token_hash']); // Klartext nie gespeichert
    }

    public function testRedeemSetsPasswordActivatesAndConsumes(): void
    {
        $svc = new PasswordResetService();
        $token = $svc->create($this->userId, 'invite');

        $this->assertNull($svc->redeem($token, 'correct-horse-battery'));
        $this->assertSame('active', $this->userStatus()); // invited -> active
        $hash = (string)ConnectionManager::get('default')->execute(
            'SELECT password_hash FROM users WHERE id = :id',
            ['id' => $this->userId],
        )->fetch('assoc')['password_hash'];
        $this->assertNotEmpty($hash);
        $this->assertTrue(password_verify('correct-horse-battery', $hash));

        // Token verbraucht -> zweite Einlösung abgelehnt.
        $this->assertNotNull($svc->redeem($token, 'another-strong-pw'));
    }

    public function testRedeemRejectsShortInvalidExpired(): void
    {
        $svc = new PasswordResetService();
        $token = $svc->create($this->userId, 'invite');

        // Zu kurz.
        $this->assertNotNull($svc->redeem($token, 'short'));
        $this->assertSame('invited', $this->userStatus());

        // Unbekannter Token.
        $this->assertNotNull($svc->redeem('voellig-unbekannt', 'correct-horse-battery'));

        // Abgelaufen.
        ConnectionManager::get('default')->execute(
            "UPDATE password_reset_tokens SET expires_at = now() - interval '1 hour' WHERE user_id = :u",
            ['u' => $this->userId],
        );
        $this->assertNotNull($svc->redeem($token, 'correct-horse-battery'));
        $this->assertSame('invited', $this->userStatus());
    }

    public function testRedeemInvalidatesOtherOpenTokens(): void
    {
        $svc = new PasswordResetService();
        $t1 = $svc->create($this->userId, 'invite');
        $t2 = $svc->create($this->userId, 'reset');

        $this->assertNull($svc->redeem($t1, 'correct-horse-battery'));
        // Der zweite, zuvor offene Token ist jetzt ebenfalls entwertet.
        $this->assertNotNull($svc->redeem($t2, 'correct-horse-battery'));
    }

    public function testMinPasswordLengthFromSettings(): void
    {
        $this->assertGreaterThanOrEqual(8, (new PasswordResetService())->minPasswordLength());
    }
}
