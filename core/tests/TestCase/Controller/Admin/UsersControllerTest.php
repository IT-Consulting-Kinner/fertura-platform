<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integration test for the users admin GUI (admin area `user_group_admin`):
 * list/detail/create/edit, status lifecycle with **self-lockout protection**
 * (no self-deactivation/-anonymization, the last active user_group_admin is
 * protected, activation only with a password), admin area toggle, invitation
 * tokens, and admin password setting.
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
        // Logged-in admin: active, holds user_group_admin (and is its only
        // active holder -> last-admin protection applies to themselves).
        $this->adminId = $this->makeUser('uadmin', 'active');
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $this->adminId, 'a' => 'user_group_admin'],
        );
        // Second user without an admin area (target of most actions).
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
        // The last-active-admin trigger and similar do not apply to hard deletes;
        // test users are uniquely identifiable via the email domain.
        $conn->execute(
            'DELETE FROM user_admin_areas WHERE user_id IN '
            . "(SELECT id FROM users WHERE email LIKE '%@zzusers.local')",
        );
        // Test groups (mandatory-group flow) incl. their membership rows.
        $conn->execute(
            'DELETE FROM groups_users WHERE group_id IN '
            . "(SELECT id FROM \"groups\" WHERE name LIKE 'zzusers_grp_%')",
        );
        $conn->execute("DELETE FROM \"groups\" WHERE name LIKE 'zzusers_grp_%'");
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzusers.local'");
        // Foreign tenants seeded by the cross-tenant isolation test (after their users).
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zzt_other_%'");
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
        $this->assertResponseContains('user_group_admin'); // area list rendered
    }

    public function testOtherUserViewOffersSelfManagement(): void
    {
        // Viewing ANOTHER user keeps the admin onboarding + lifecycle actions.
        $this->login();
        $this->get('/admin/users/view/' . $this->memberId);

        $this->assertResponseOk();
        // DashedRoute: setStatus -> set-status, setPassword -> set-password.
        $this->assertResponseContains('/admin/users/set-status/' . $this->memberId); // (de)activate
        $this->assertResponseContains('/admin/users/anonymize/' . $this->memberId);
        $this->assertResponseContains('/admin/users/invite/' . $this->memberId);
        $this->assertResponseContains('/admin/users/set-password/' . $this->memberId);
    }

    public function testOwnViewHidesSelfManagement(): void
    {
        // On one's OWN user page the deactivate / anonymize / invite+password block
        // is hidden — those belong in "My Profile" (self-deactivate/-anonymize are
        // refused server-side anyway, tested separately). The edit link stays.
        $this->login();
        $this->get('/admin/users/view/' . $this->adminId);

        $this->assertResponseOk();
        $this->assertResponseNotContains('/admin/users/set-status/' . $this->adminId);
        $this->assertResponseNotContains('/admin/users/anonymize/' . $this->adminId);
        $this->assertResponseNotContains('/admin/users/invite/' . $this->adminId);
        $this->assertResponseNotContains('/admin/users/set-password/' . $this->adminId);
        $this->assertResponseContains('/admin/users/edit/' . $this->adminId); // edit stays
    }

    public function testCreateFormGroupFieldIsAReferenceField(): void
    {
        // Finding 2: the group select on the create form carries a "create a new
        // group" link (new tab) + an options-refresh button, so a missing group can
        // be created without leaving the form. The link is area-gated to
        // user_group_admin, which the acting admin holds here.
        $this->login();
        $this->get('/admin/users');

        $this->assertResponseOk();
        $this->assertResponseContains('href="/admin/groups?create=1"');
        $this->assertResponseContains('data-options-refresh="/admin/groups/options"');
    }

    public function testViewUnknownRedirects(): void
    {
        $this->login();
        $this->get('/admin/users/view/00000000-0000-0000-0000-000000000000');
        $this->assertRedirect(['action' => 'index']);
    }

    public function testMalformedIdRedirectsInsteadOf500(): void
    {
        // Malformed UUID in the URL: UUID guard -> redirect instead of
        // 22P02 ("invalid input syntax for type uuid") -> 500.
        $this->login();

        $this->get('/admin/users/view/garbage');
        $this->assertRedirect(['action' => 'index']);

        $this->post('/admin/users/setStatus/garbage/disabled');
        $this->assertRedirect(['action' => 'index']);

        $this->post('/admin/users/toggleArea/garbage/user_group_admin');
        $this->assertRedirect(['action' => 'index']);

        $this->post('/admin/users/invite/garbage');
        $this->assertRedirect(['action' => 'index']);

        $this->post('/admin/users/anonymize/garbage');
        $this->assertRedirect(['action' => 'index']);
    }

    /** Creates an active group in the acting admin's (default) tenant. */
    private function makeGroup(): string
    {
        return (string)ConnectionManager::get('default')->execute(
            'INSERT INTO "groups" (name, tenant_id) VALUES (:n, '
            . "'00000000-0000-0000-0000-000000000001') RETURNING id",
            ['n' => 'zzusers_grp_' . bin2hex(random_bytes(3))],
        )->fetch('assoc')['id'];
    }

    public function testAddCreatesInvitedUserWithGroupMembership(): void
    {
        $this->login();
        $groupId = $this->makeGroup();
        $email = 'newuser_' . bin2hex(random_bytes(3)) . '@zzusers.local';
        $this->post('/admin/users/add', [
            'username' => 'zztest_new_' . bin2hex(random_bytes(3)),
            'email' => $email,
            'group_id' => $groupId,
        ]);

        $this->assertRedirect(['action' => 'index']);
        $row = ConnectionManager::get('default')->execute(
            'SELECT id, status FROM users WHERE email = :e',
            ['e' => $email],
        )->fetch('assoc');
        $this->assertNotFalse($row);
        $this->assertSame('invited', $row['status']); // invitation instead of directly active
        // Mandatory group: the membership row exists right after creation.
        $member = ConnectionManager::get('default')->execute(
            'SELECT 1 FROM groups_users WHERE group_id = :g AND user_id = :u',
            ['g' => $groupId, 'u' => $row['id']],
        )->fetch();
        $this->assertNotFalse($member, 'new user is a member of the chosen group');
    }

    public function testAddWithoutGroupIsRejected(): void
    {
        // No group-less users: omitting (or faking) the group re-renders the
        // form with an error and creates nothing.
        $this->login();
        $email = 'nogroup_' . bin2hex(random_bytes(3)) . '@zzusers.local';
        $this->post('/admin/users/add', [
            'username' => 'zztest_nogroup_' . bin2hex(random_bytes(3)),
            'email' => $email,
        ]);

        $this->assertResponseOk(); // re-render, not a success redirect
        $row = ConnectionManager::get('default')->execute(
            'SELECT 1 FROM users WHERE email = :e',
            ['e' => $email],
        )->fetch();
        $this->assertFalse($row, 'no user was created without a group');
    }

    public function testAddRejectsDuplicateEmailWithCleanError(): void
    {
        // A duplicate email must fail with a clean validation error (re-render,
        // HTTP 200) instead of a DB unique-violation -> 500, and must not insert
        // a second row. Case-insensitive, mirroring the lower(email) DB index.
        $this->login();
        $email = 'taken_' . bin2hex(random_bytes(3)) . '@zzusers.local';
        $this->makeUser('taken', 'active'); // unrelated existing user
        ConnectionManager::get('default')->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active')",
            ['u' => 'zztest_owner_' . bin2hex(random_bytes(3)), 'e' => $email],
        );

        $groupId = $this->makeGroup(); // valid group -> reaches the email rule
        $this->post('/admin/users/add', [
            'username' => 'zztest_dupemail_' . bin2hex(random_bytes(3)),
            'email' => strtoupper($email), // same email, different case
            'group_id' => $groupId,
        ]);

        $this->assertResponseOk(); // re-render with inline error, NOT a redirect (success) or 500
        // Review finding: the re-rendered form must KEEP the chosen group —
        // patchEntity drops group_id (mass-assignment guard), the controller
        // re-sets it on the entity so the select stays preselected.
        $this->assertResponseContains('value="' . $groupId . '" selected');
        $this->assertSame(
            1,
            (int)ConnectionManager::get('default')->execute(
                'SELECT count(*) AS c FROM users WHERE lower(email) = lower(:e)',
                ['e' => $email],
            )->fetch('assoc')['c'],
            'The duplicate email must not have created a second user.',
        );
    }

    public function testDeactivateMemberWorksAndReactivateNeedsPassword(): void
    {
        $this->login();

        $this->post('/admin/users/setStatus/' . $this->memberId . '/disabled');
        $this->assertRedirect(['action' => 'view', $this->memberId]);
        $this->assertSame('disabled', $this->userCol($this->memberId, 'status'));
        $this->assertNotNull($this->userCol($this->memberId, 'deactivated_at'));

        // Reactivating without a password hash -> rejected (ch. 27.15).
        $this->post('/admin/users/setStatus/' . $this->memberId . '/active');
        $this->assertSame('disabled', $this->userCol($this->memberId, 'status'));
    }

    public function testSelfDeactivateBlocked(): void
    {
        $this->login();
        $this->post('/admin/users/setStatus/' . $this->adminId . '/disabled');
        $this->assertSame('active', $this->userCol($this->adminId, 'status')); // protection applies
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
        $this->assertSame(0, $count()); // revoke ok: admin remains as a holder
    }

    public function testRevokeLastUserGroupAdminBlocked(): void
    {
        $this->login();
        // The logged-in admin is the only active holder -> revoke rejected.
        $this->post('/admin/users/toggleArea/' . $this->adminId . '/user_group_admin');
        $held = ConnectionManager::get('default')->execute(
            "SELECT 1 FROM user_admin_areas WHERE user_id = :u AND admin_area_key = 'user_group_admin'",
            ['u' => $this->adminId],
        )->fetch();
        $this->assertNotFalse($held); // still a holder
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

        // Too short -> rejected, status stays invited.
        $this->post('/admin/users/setPassword/' . $invited, ['password' => 'short']);
        $this->assertSame('invited', $this->userCol($invited, 'status'));
        $this->assertNull($this->userCol($invited, 'password_hash'));

        // Long enough -> hash set, invited -> active.
        $this->post('/admin/users/setPassword/' . $invited, ['password' => 'correct-horse-battery']);
        $this->assertSame('active', $this->userCol($invited, 'status'));
        $this->assertNotNull($this->userCol($invited, 'password_hash'));
    }

    public function testCrossTenantUsersAreInvisibleAndUnreachable(): void
    {
        $conn = ConnectionManager::get('default');
        // A user in a DIFFERENT tenant must be invisible AND unreachable by every
        // action — `users` has no RLS, so the controller's explicit tenant filter is
        // the only isolation. The headline vector is setPassword (account takeover).
        $otherTenant = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zzt_other_' || substr(md5(random()::text), 1, 8), 'Other') RETURNING id",
        )->fetch('assoc')['id'];
        $foreign = (string)$conn->execute(
            "INSERT INTO users (username, email, status, tenant_id) VALUES (:u, :e, 'active', :t) RETURNING id",
            [
                'u' => 'zztest_foreign_' . bin2hex(random_bytes(3)),
                'e' => 'foreign_' . bin2hex(random_bytes(3)) . '@zzusers.local',
                't' => $otherTenant,
            ],
        )->fetch('assoc')['id'];

        $this->login();

        // Invisible in the list.
        $this->get('/admin/users');
        $this->assertResponseOk();
        $this->assertResponseNotContains($foreign);

        // view -> treated as unknown (redirect, not rendered).
        $this->get('/admin/users/view/' . $foreign);
        $this->assertRedirect(['action' => 'index']);

        // setPassword (account takeover) -> NO effect.
        $this->post('/admin/users/setPassword/' . $foreign, ['password' => 'attacker-chosen-pass']);
        $this->assertNull($this->userCol($foreign, 'password_hash'));

        // setStatus + anonymize -> NO effect.
        $this->post('/admin/users/setStatus/' . $foreign . '/disabled');
        $this->assertSame('active', $this->userCol($foreign, 'status'));
        $this->post('/admin/users/anonymize/' . $foreign);
        $this->assertSame('active', $this->userCol($foreign, 'status'));

        // toggleArea -> NO admin-area grant created on the foreign user.
        $this->post('/admin/users/toggleArea/' . $foreign . '/user_group_admin');
        $this->assertFalse(
            $conn->execute('SELECT 1 FROM user_admin_areas WHERE user_id = :u', ['u' => $foreign])->fetch(),
        );

        // edit -> NO effect.
        $this->post('/admin/users/edit/' . $foreign, ['username' => 'zztest_hijacked', 'email' => 'x_' . bin2hex(random_bytes(3)) . '@zzusers.local']);
        $this->assertNotSame('zztest_hijacked', $this->userCol($foreign, 'username'));
    }

    public function testLastAdminProtectionIsPerTenant(): void
    {
        $conn = ConnectionManager::get('default');
        // Another tenant's OWN active user_group_admin must NOT count toward THIS
        // tenant's last-admin protection — otherwise a tenant could strip its own last
        // admin merely because a different tenant still has one.
        $otherTenant = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zzt_other_' || substr(md5(random()::text), 1, 8), 'Other') RETURNING id",
        )->fetch('assoc')['id'];
        $otherAdmin = (string)$conn->execute(
            "INSERT INTO users (username, email, status, tenant_id) VALUES (:u, :e, 'active', :t) RETURNING id",
            ['u' => 'zztest_oadmin_' . bin2hex(random_bytes(3)), 'e' => 'oadmin_' . bin2hex(random_bytes(3)) . '@zzusers.local', 't' => $otherTenant],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $otherAdmin, 'a' => 'user_group_admin'],
        );

        $this->login(); // adminId is the sole user_group_admin of the DEFAULT tenant
        // Revoking THIS tenant's only admin stays blocked (the other tenant's does not count).
        $this->post('/admin/users/toggleArea/' . $this->adminId . '/user_group_admin');
        $held = $conn->execute(
            "SELECT 1 FROM user_admin_areas WHERE user_id = :u AND admin_area_key = 'user_group_admin'",
            ['u' => $this->adminId],
        )->fetch();
        $this->assertNotFalse($held); // still protected per-tenant

        $conn->execute('DELETE FROM user_admin_areas WHERE user_id = :u', ['u' => $otherAdmin]);
        $conn->execute('DELETE FROM users WHERE id = :u', ['u' => $otherAdmin]);
        $conn->execute('DELETE FROM tenants WHERE id = :t', ['t' => $otherTenant]);
    }

    public function testAnonymizeSelfBlockedAndMemberWorks(): void
    {
        $this->login();

        $this->post('/admin/users/anonymize/' . $this->adminId);
        $this->assertSame('active', $this->userCol($this->adminId, 'status')); // self-protection

        $this->post('/admin/users/anonymize/' . $this->memberId);
        $this->assertRedirect(['action' => 'index']);
        $this->assertSame('anonymized', $this->userCol($this->memberId, 'status'));
    }
}
