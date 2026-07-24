<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use App\Test\TestCase\AdminAreaSeedTrait;
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
    use AdminAreaSeedTrait;

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
        $this->grantAdminAreas($this->adminId, 'user_group_admin');
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
        // Test groups (mandatory-group flow + the AdminAreaSeedTrait's zzseedarea_
        // groups) incl. their membership rows; group_admin_areas cascade on delete.
        $conn->execute(
            'DELETE FROM groups_users WHERE group_id IN '
            . "(SELECT id FROM \"groups\" WHERE name LIKE 'zzusers_grp_%' OR name LIKE 'zzseedarea_%')",
        );
        $conn->execute("DELETE FROM \"groups\" WHERE name LIKE 'zzusers_grp_%' OR name LIKE 'zzseedarea_%'");
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
        $this->assertResponseContains('/admin/users/set-password/' . $this->memberId);
        // The invitation link is offered only while a user is still "invited";
        // memberId is active, so it is NOT shown (see testViewOffersInviteOnlyWhenInvited).
        $this->assertResponseNotContains('/admin/users/invite/' . $this->memberId);
    }

    public function testOwnViewHidesSelfManagement(): void
    {
        // On one's OWN user page the deactivate / anonymize / invite+password block
        // is hidden — those belong in "My Profile" (self-deactivate/-anonymize are
        // refused server-side anyway, tested separately). The edit link is hidden
        // too: editing one's own account (incl. its groups) belongs in "My Profile"
        // and is refused server-side (tested in testSelfEditBlocked).
        $this->login();
        $this->get('/admin/users/view/' . $this->adminId);

        $this->assertResponseOk();
        $this->assertResponseNotContains('/admin/users/set-status/' . $this->adminId);
        $this->assertResponseNotContains('/admin/users/anonymize/' . $this->adminId);
        $this->assertResponseNotContains('/admin/users/invite/' . $this->adminId);
        $this->assertResponseNotContains('/admin/users/set-password/' . $this->adminId);
        $this->assertResponseNotContains('/admin/users/edit/' . $this->adminId); // self-edit hidden
    }

    public function testCreateFormHasGroupMultiselect(): void
    {
        // The create form assigns groups via a MULTI-select (<select multiple>, name
        // group_ids[]) — a user may start in several groups at once — plus a reload
        // button (UiKit options-refresh) and a "create a new group" link so a missing
        // group can be created and picked up without leaving the form.
        $this->login();
        $this->makeGroup(); // at least one active group -> options render
        $this->get('/admin/users');

        $this->assertResponseOk();
        $this->assertResponseContains('name="group_ids[]"');
        $this->assertResponseContains('multiple'); // real multi-select, not checkboxes
        $this->assertResponseContains('data-options-refresh="/admin/groups/options"'); // reload button
        $this->assertResponseContains('href="/admin/groups?create=1"');
        // The admin shell loads Tom Select (self-hosted) so ui.js enhances the selects.
        $this->assertResponseContains('tom-select.complete.min');
        $this->assertResponseContains('tom-select.bootstrap5');
        // Single-line inputs (not the <textarea> a `text` column defaults to).
        $this->assertResponseNotContains('<textarea name="username"');
        $this->assertResponseNotContains('<textarea name="first_name"');
        $this->assertResponseNotContains('<textarea name="last_name"');
        $this->assertResponseContains('type="text" name="username"');
    }

    public function testEditFormFieldsAreSingleLine(): void
    {
        // username / first_name / last_name must be single-line <input>s on the edit
        // form too — a `text`-typed column would otherwise render a <textarea>.
        $this->login();
        $this->get('/admin/users/edit/' . $this->memberId);

        $this->assertResponseOk();
        $this->assertResponseNotContains('<textarea name="username"');
        $this->assertResponseNotContains('<textarea name="first_name"');
        $this->assertResponseNotContains('<textarea name="last_name"');
        $this->assertResponseContains('type="text" name="username"');
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

    /** Makes $userId a member of $groupId (membership editor fixtures). */
    private function addMembership(string $userId, string $groupId): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO groups_users (group_id, user_id) VALUES (:g, :u) ON CONFLICT DO NOTHING',
            ['g' => $groupId, 'u' => $userId],
        );
    }

    /**
     * The user's raw group ids. No tenant filter: a bare ConnectionManager query
     * runs OUTSIDE the request, so the RLS GUC (core.current_tenant()) is unset —
     * filtering on it here would always return empty. All test data lives in the
     * default tenant and the controller blocks cross-tenant rows, so a raw read is
     * both correct and stronger (it would surface a stray foreign membership).
     *
     * @return list<string>
     */
    private function membershipIds(string $userId): array
    {
        $rows = ConnectionManager::get('default')->execute(
            'SELECT group_id FROM groups_users WHERE user_id = :u',
            ['u' => $userId],
        )->fetchAll('assoc');

        return array_map(static fn(array $r): string => (string)$r['group_id'], $rows);
    }

    public function testAddCreatesInvitedUserWithGroupMembership(): void
    {
        $this->login();
        $groupId = $this->makeGroup();
        $email = 'newuser_' . bin2hex(random_bytes(3)) . '@zzusers.local';
        $this->post('/admin/users/add', [
            'username' => 'zztest_new_' . bin2hex(random_bytes(3)),
            'email' => $email,
            'group_ids' => [$groupId],
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
            'group_ids' => [$groupId],
        ]);

        $this->assertResponseOk(); // re-render with inline error, NOT a redirect (success) or 500
        // Review finding: the re-rendered form must KEEP the chosen group(s) —
        // patchEntity drops group_ids (mass-assignment guard), the controller
        // re-sets it on the entity so the <select multiple> option stays selected.
        $this->assertResponseRegExp('/value="' . preg_quote($groupId, '/') . '"[^>]*selected/');
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

    public function testInviteRejectedForActiveUser(): void
    {
        // An invitation link sets an INITIAL password -> only for a still-"invited"
        // account. Inviting an already-onboarded (active) user is refused server-side
        // and creates no token (belt to the hidden UI button).
        $active = $this->makeUser('uactive', 'active');
        $this->login();
        $this->post('/admin/users/invite/' . $active);

        $this->assertRedirect(['action' => 'view', $active]);
        $this->assertSame(0, (int)ConnectionManager::get('default')->execute(
            "SELECT count(*) AS c FROM password_reset_tokens WHERE user_id = :u AND purpose = 'invite'",
            ['u' => $active],
        )->fetch('assoc')['c'], 'no invite token for an active user');
    }

    public function testViewOffersInviteOnlyWhenInvited(): void
    {
        $this->login();
        $invited = $this->makeUser('uinv', 'invited');
        $active = $this->makeUser('uact', 'active');

        $this->get('/admin/users/view/' . $invited);
        $this->assertResponseContains('/admin/users/invite/' . $invited); // offered while invited

        $this->get('/admin/users/view/' . $active);
        $this->assertResponseNotContains('/admin/users/invite/' . $active); // gone once onboarded
    }

    public function testListSearchAndStatusFilter(): void
    {
        $this->login();
        $disabled = $this->makeUser('ufindme', 'disabled');

        // Search narrows by a username/email fragment (ILIKE contains).
        $this->get('/admin/users?_lp=1&q=ufindme&per_page=25');
        $this->assertResponseOk();
        $this->assertResponseContains('zztest_ufindme_'); // matched
        $this->assertResponseNotContains('zztest_umember_'); // filtered out

        // Status filter narrows by status (and clears the previous q).
        $this->get('/admin/users?_lp=1&status=disabled');
        $this->assertResponseOk();
        $this->assertResponseContains('zztest_ufindme_'); // disabled matches
        $this->assertResponseNotContains('zztest_uadmin_'); // the active admin is excluded
        unset($disabled);
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

        // edit -> NO effect.
        $this->post('/admin/users/edit/' . $foreign, ['username' => 'zztest_hijacked', 'email' => 'x_' . bin2hex(random_bytes(3)) . '@zzusers.local']);
        $this->assertNotSame('zztest_hijacked', $this->userCol($foreign, 'username'));
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

    public function testCreateWithMultipleGroups(): void
    {
        // A user may be created in SEVERAL groups at once (multiselect).
        $this->login();
        $g1 = $this->makeGroup();
        $g2 = $this->makeGroup();
        $email = 'multi_' . bin2hex(random_bytes(3)) . '@zzusers.local';
        $this->post('/admin/users/add', [
            'username' => 'zztest_multi_' . bin2hex(random_bytes(3)),
            'email' => $email,
            'group_ids' => [$g1, $g2],
        ]);

        $this->assertRedirect(['action' => 'index']);
        $newId = (string)ConnectionManager::get('default')
            ->execute('SELECT id FROM users WHERE email = :e', ['e' => $email])
            ->fetch('assoc')['id'];
        $ids = $this->membershipIds($newId);
        sort($ids);
        $expected = [$g1, $g2];
        sort($expected);
        $this->assertSame($expected, $ids, 'new user is a member of BOTH chosen groups');
    }

    public function testCreateIgnoresForeignGroupId(): void
    {
        // A POSTed group id from ANOTHER tenant must be silently dropped — it must
        // never pull the new user into a foreign tenant's group. Here it is the ONLY
        // id posted, so the create is rejected (mandatory group) and nothing is made.
        $conn = ConnectionManager::get('default');
        $otherTenant = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zzt_other_' || substr(md5(random()::text), 1, 8), 'Other') RETURNING id",
        )->fetch('assoc')['id'];
        $foreignGroup = (string)$conn->execute(
            'INSERT INTO "groups" (name, tenant_id) VALUES (:n, :t) RETURNING id',
            ['n' => 'zzusers_grp_foreign_' . bin2hex(random_bytes(3)), 't' => $otherTenant],
        )->fetch('assoc')['id'];

        $this->login();
        $email = 'foreigngrp_' . bin2hex(random_bytes(3)) . '@zzusers.local';
        $this->post('/admin/users/add', [
            'username' => 'zztest_foreigngrp_' . bin2hex(random_bytes(3)),
            'email' => $email,
            'group_ids' => [$foreignGroup],
        ]);

        $this->assertResponseOk(); // rejected re-render (no valid group), not a redirect
        $this->assertFalse(
            $conn->execute('SELECT 1 FROM users WHERE email = :e', ['e' => $email])->fetch(),
            'no user created from a foreign-only group selection',
        );
    }

    public function testSelfEditBlocked(): void
    {
        // Editing one's OWN account here is refused (belongs in "My Profile") — both
        // the GET form and a POSTed change. This is what stops an admin from stripping
        // their own groups (incl. the admin group) and locking themselves out.
        $this->login();

        $this->get('/admin/users/edit/' . $this->adminId);
        $this->assertRedirect(['action' => 'view', $this->adminId]);

        $before = $this->userCol($this->adminId, 'username');
        $this->post('/admin/users/edit/' . $this->adminId, [
            'username' => 'zztest_selfhijack_' . bin2hex(random_bytes(3)),
            'email' => 'selfhijack_' . bin2hex(random_bytes(3)) . '@zzusers.local',
        ]);
        $this->assertRedirect(['action' => 'view', $this->adminId]);
        $this->assertSame($before, $this->userCol($this->adminId, 'username'), 'own account unchanged');
    }

    public function testEditShowsGroupManagement(): void
    {
        // The edit page lists the user's current groups (with a remove control) and
        // offers the tenant's OTHER active groups to add. Two held groups so a remove
        // control renders — the LAST/only group is intentionally non-removable
        // (mandatory group), which testRemoveLastGroupBlocked covers.
        $this->login();
        $held1 = $this->makeGroup();
        $held2 = $this->makeGroup();
        $addable = $this->makeGroup();
        $this->addMembership($this->memberId, $held1);
        $this->addMembership($this->memberId, $held2);

        $this->get('/admin/users/edit/' . $this->memberId);
        $this->assertResponseOk();
        // remove control for a held group; add control offers the still-available one.
        $this->assertResponseContains('/admin/users/remove-group/' . $this->memberId . '/' . $held1);
        $this->assertResponseContains('/admin/users/add-group/' . $this->memberId);
        $this->assertResponseContains('value="' . $addable . '"');
    }

    public function testAddGroupAddsMembership(): void
    {
        $this->login();
        $g1 = $this->makeGroup();
        $g2 = $this->makeGroup();
        $this->addMembership($this->memberId, $g1);

        $this->post('/admin/users/addGroup/' . $this->memberId, ['group_id' => $g2]);
        $this->assertRedirect(['action' => 'edit', $this->memberId]);
        $ids = $this->membershipIds($this->memberId);
        $this->assertContains($g2, $ids);
        $this->assertContains($g1, $ids); // existing membership preserved
    }

    public function testAddGroupRejectsForeignGroup(): void
    {
        // A foreign-tenant group id must not be addable.
        $conn = ConnectionManager::get('default');
        $g1 = $this->makeGroup();
        $this->addMembership($this->memberId, $g1);
        $otherTenant = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zzt_other_' || substr(md5(random()::text), 1, 8), 'Other') RETURNING id",
        )->fetch('assoc')['id'];
        $foreignGroup = (string)$conn->execute(
            'INSERT INTO "groups" (name, tenant_id) VALUES (:n, :t) RETURNING id',
            ['n' => 'zzusers_grp_foreign_' . bin2hex(random_bytes(3)), 't' => $otherTenant],
        )->fetch('assoc')['id'];

        $this->login();
        $this->post('/admin/users/addGroup/' . $this->memberId, ['group_id' => $foreignGroup]);
        $this->assertFalse(
            $conn->execute(
                'SELECT 1 FROM groups_users WHERE user_id = :u AND group_id = :g',
                ['u' => $this->memberId, 'g' => $foreignGroup],
            )->fetch(),
            'a foreign-tenant group must not become a membership',
        );
    }

    public function testRemoveGroupRemovesMembership(): void
    {
        $this->login();
        $g1 = $this->makeGroup();
        $g2 = $this->makeGroup();
        $this->addMembership($this->memberId, $g1);
        $this->addMembership($this->memberId, $g2);

        $this->post('/admin/users/removeGroup/' . $this->memberId . '/' . $g2);
        $this->assertRedirect(['action' => 'edit', $this->memberId]);
        $ids = $this->membershipIds($this->memberId);
        $this->assertNotContains($g2, $ids);
        $this->assertContains($g1, $ids); // the other stays -> re-addable
    }

    public function testRemoveLastGroupBlocked(): void
    {
        // No group-less users: the last group cannot be removed.
        $this->login();
        $only = $this->makeGroup();
        $this->addMembership($this->memberId, $only);

        $this->post('/admin/users/removeGroup/' . $this->memberId . '/' . $only);
        $this->assertContains($only, $this->membershipIds($this->memberId), 'last group is protected');
    }

    public function testGroupMutationOnSelfBlocked(): void
    {
        // Group edits on one's OWN account are refused (mirrors the self-edit block):
        // an admin must not add/remove their own groups here.
        $this->login();
        $g1 = $this->makeGroup();
        $g2 = $this->makeGroup();
        $this->addMembership($this->adminId, $g1);
        $this->addMembership($this->adminId, $g2);

        $this->post('/admin/users/addGroup/' . $this->adminId, ['group_id' => $this->makeGroup()]);
        $this->post('/admin/users/removeGroup/' . $this->adminId . '/' . $g2);
        $ids = $this->membershipIds($this->adminId);
        $this->assertContains($g1, $ids);
        $this->assertContains($g2, $ids); // neither add nor remove took effect
    }

    public function testGroupMutationCrossTenantBlocked(): void
    {
        // A foreign user is unreachable by the membership editor (users has no RLS).
        $conn = ConnectionManager::get('default');
        $otherTenant = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zzt_other_' || substr(md5(random()::text), 1, 8), 'Other') RETURNING id",
        )->fetch('assoc')['id'];
        $foreign = (string)$conn->execute(
            "INSERT INTO users (username, email, status, tenant_id) VALUES (:u, :e, 'active', :t) RETURNING id",
            ['u' => 'zztest_foreign_' . bin2hex(random_bytes(3)), 'e' => 'foreign_' . bin2hex(random_bytes(3)) . '@zzusers.local', 't' => $otherTenant],
        )->fetch('assoc')['id'];
        $ownGroup = $this->makeGroup(); // a group in the ACTING admin's tenant

        $this->login();
        $this->post('/admin/users/addGroup/' . $foreign, ['group_id' => $ownGroup]);
        $this->assertRedirect(['action' => 'index']); // treated as unknown user
        $this->assertFalse(
            $conn->execute('SELECT 1 FROM groups_users WHERE user_id = :u', ['u' => $foreign])->fetch(),
            'no membership created on a cross-tenant user',
        );
    }
}
