<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integrationstest der Benutzer-Admin-GUI (Administrationsbereich
 * `user_group_admin`): Liste/Detail/Anlegen/Bearbeiten, Status-Lifecycle mit
 * **Selbst-Aussperr-Schutz** (kein Selbst-Deaktivieren/-Anonymisieren, letzter
 * aktiver user_group_admin geschützt, Aktivierung nur mit Passwort),
 * Admin-Bereichs-Toggle, Einladungs-Token und Admin-Passwort-Setzen.
 */
class UsersControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private string $adminId;
    private string $memberId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "INSERT INTO admin_areas (area_key, label, sort_order) VALUES ('user_group_admin', 'Users', 10) "
            . 'ON CONFLICT (area_key) DO NOTHING',
        );
        // Angemeldeter Admin: aktiv, hält user_group_admin (und ist dessen
        // einziger aktiver Träger -> Letzter-Admin-Schutz greift auf ihm selbst).
        $this->adminId = $this->makeUser('uadmin', 'active');
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $this->adminId, 'a' => 'user_group_admin'],
        );
        // Zweiter Benutzer ohne Admin-Bereich (Ziel der meisten Aktionen).
        $this->memberId = $this->makeUser('umember', 'active');
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
            'DELETE FROM password_reset_tokens WHERE user_id IN '
            . "(SELECT id FROM users WHERE email LIKE '%@zzusers.local')",
        );
        // Letzter-aktiver-Admin-Trigger u. ä. greifen nicht auf hartes Löschen;
        // Test-Benutzer sind über die E-Mail-Domain eindeutig identifizierbar.
        $conn->execute(
            'DELETE FROM user_admin_areas WHERE user_id IN '
            . "(SELECT id FROM users WHERE email LIKE '%@zzusers.local')",
        );
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzusers.local'");
    }

    private function makeUser(string $prefix, string $status, ?string $passwordHash = null): string
    {
        return (string)ConnectionManager::get('default')->execute(
            'INSERT INTO users (username, email, status, password_hash) VALUES (:u, :e, :s, :p) RETURNING id',
            [
                'u' => 'zztest_' . $prefix . '_' . bin2hex(random_bytes(3)),
                'e' => $prefix . '_' . bin2hex(random_bytes(3)) . '@zzusers.local',
                's' => $status,
                'p' => $passwordHash,
            ],
        )->fetch('assoc')['id'];
    }

    private function login(): void
    {
        $this->session(['Auth' => ['id' => $this->adminId, 'username' => 'zztest_uadmin', 'email' => 'a@zzusers.local']]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
    }

    private function userCol(string $id, string $col): mixed
    {
        return ConnectionManager::get('default')
            ->execute('SELECT ' . $col . ' AS v FROM users WHERE id = :id', ['id' => $id])
            ->fetch('assoc')['v'];
    }

    public function testIndexAndViewRender(): void
    {
        $this->login();
        $this->get('/admin/users');
        $this->assertResponseOk();
        $this->assertResponseContains('zztest_umember_');

        $this->get('/admin/users/view/' . $this->memberId);
        $this->assertResponseOk();
        $this->assertResponseContains('user_group_admin'); // Bereichs-Liste gerendert
    }

    public function testViewUnknownRedirects(): void
    {
        $this->login();
        $this->get('/admin/users/view/00000000-0000-0000-0000-000000000000');
        $this->assertRedirect(['action' => 'index']);
    }

    public function testAddCreatesInvitedUser(): void
    {
        $this->login();
        $email = 'newuser_' . bin2hex(random_bytes(3)) . '@zzusers.local';
        $this->post('/admin/users/add', [
            'username' => 'zztest_new_' . bin2hex(random_bytes(3)),
            'email' => $email,
        ]);

        $this->assertRedirect(['action' => 'index']);
        $row = ConnectionManager::get('default')->execute(
            'SELECT status FROM users WHERE email = :e',
            ['e' => $email],
        )->fetch('assoc');
        $this->assertNotFalse($row);
        $this->assertSame('invited', $row['status']); // Einladung statt Direkt-Aktiv
    }

    public function testDeactivateMemberWorksAndReactivateNeedsPassword(): void
    {
        $this->login();

        $this->post('/admin/users/setStatus/' . $this->memberId . '/disabled');
        $this->assertRedirect(['action' => 'view', $this->memberId]);
        $this->assertSame('disabled', $this->userCol($this->memberId, 'status'));
        $this->assertNotNull($this->userCol($this->memberId, 'deactivated_at'));

        // Reaktivieren ohne Passwort-Hash -> abgelehnt (Kap. 27.15).
        $this->post('/admin/users/setStatus/' . $this->memberId . '/active');
        $this->assertSame('disabled', $this->userCol($this->memberId, 'status'));
    }

    public function testSelfDeactivateBlocked(): void
    {
        $this->login();
        $this->post('/admin/users/setStatus/' . $this->adminId . '/disabled');
        $this->assertSame('active', $this->userCol($this->adminId, 'status')); // Schutz greift
    }

    public function testInvalidStatusRejected(): void
    {
        $this->login();
        $this->post('/admin/users/setStatus/' . $this->memberId . '/anonymized');
        $this->assertRedirect(['action' => 'index']);
        $this->assertSame('active', $this->userCol($this->memberId, 'status'));
    }

    public function testToggleAreaGrantAndRevoke(): void
    {
        $this->login();
        $count = fn(): int => (int)ConnectionManager::get('default')->execute(
            "SELECT count(*) AS c FROM user_admin_areas WHERE user_id = :u AND admin_area_key = 'user_group_admin'",
            ['u' => $this->memberId],
        )->fetch('assoc')['c'];

        $this->post('/admin/users/toggleArea/' . $this->memberId . '/user_group_admin');
        $this->assertSame(1, $count());

        $this->post('/admin/users/toggleArea/' . $this->memberId . '/user_group_admin');
        $this->assertSame(0, $count()); // Entzug ok: Admin bleibt als Träger übrig
    }

    public function testRevokeLastUserGroupAdminBlocked(): void
    {
        $this->login();
        // Der angemeldete Admin ist der einzige aktive Träger -> Entzug abgelehnt.
        $this->post('/admin/users/toggleArea/' . $this->adminId . '/user_group_admin');
        $held = ConnectionManager::get('default')->execute(
            "SELECT 1 FROM user_admin_areas WHERE user_id = :u AND admin_area_key = 'user_group_admin'",
            ['u' => $this->adminId],
        )->fetch();
        $this->assertNotFalse($held); // weiterhin Träger
    }

    public function testEditUpdatesFields(): void
    {
        $this->login();
        $this->post('/admin/users/edit/' . $this->memberId, [
            'username' => 'zztest_renamed_' . bin2hex(random_bytes(3)),
            'email' => 'renamed_' . bin2hex(random_bytes(3)) . '@zzusers.local',
            'first_name' => 'Re',
            'last_name' => 'Named',
        ]);
        $this->assertRedirect(['action' => 'view', $this->memberId]);
        $this->assertSame('Re', $this->userCol($this->memberId, 'first_name'));
        $this->assertSame('Named', $this->userCol($this->memberId, 'last_name'));
    }

    public function testInviteCreatesResetToken(): void
    {
        $invited = $this->makeUser('uinvited', 'invited');
        $this->login();
        $this->post('/admin/users/invite/' . $invited);

        $this->assertRedirect(['action' => 'view', $invited]);
        $tokens = (int)ConnectionManager::get('default')->execute(
            "SELECT count(*) AS c FROM password_reset_tokens WHERE user_id = :u AND purpose = 'invite'",
            ['u' => $invited],
        )->fetch('assoc')['c'];
        $this->assertSame(1, $tokens);
    }

    public function testSetPasswordActivatesInvitedAndRejectsShort(): void
    {
        $invited = $this->makeUser('upw', 'invited');
        $this->login();

        // Zu kurz -> abgelehnt, Status bleibt invited.
        $this->post('/admin/users/setPassword/' . $invited, ['password' => 'short']);
        $this->assertSame('invited', $this->userCol($invited, 'status'));
        $this->assertNull($this->userCol($invited, 'password_hash'));

        // Lang genug -> Hash gesetzt, invited -> active.
        $this->post('/admin/users/setPassword/' . $invited, ['password' => 'correct-horse-battery']);
        $this->assertSame('active', $this->userCol($invited, 'status'));
        $this->assertNotNull($this->userCol($invited, 'password_hash'));
    }

    public function testAnonymizeSelfBlockedAndMemberWorks(): void
    {
        $this->login();

        $this->post('/admin/users/anonymize/' . $this->adminId);
        $this->assertSame('active', $this->userCol($this->adminId, 'status')); // Selbst-Schutz

        $this->post('/admin/users/anonymize/' . $this->memberId);
        $this->assertRedirect(['action' => 'index']);
        $this->assertSame('anonymized', $this->userCol($this->memberId, 'status'));
    }
}
