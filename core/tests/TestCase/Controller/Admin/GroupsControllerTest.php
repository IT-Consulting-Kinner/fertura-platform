<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integration test for the groups admin GUI (admin area `user_group_admin`):
 * list, create (incl. validation), detail, active toggle, membership, and
 * granting/revoking BREAD permissions through the real render + DB effect.
 */
class GroupsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private string $userId;
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
        $this->userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_gadmin_' . bin2hex(random_bytes(3)), 'e' => 'gadmin_' . bin2hex(random_bytes(3)) . '@zzgroup.local'],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $this->userId, 'a' => 'user_group_admin'],
        );
        $this->memberId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_member_' . bin2hex(random_bytes(3)), 'e' => 'member_' . bin2hex(random_bytes(3)) . '@zzgroup.local'],
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
        // Permissions/memberships of the test groups first (FK), then groups/users.
        $conn->execute(
            'DELETE FROM group_resource_permissions WHERE group_id IN '
            . "(SELECT id FROM \"groups\" WHERE name LIKE 'zztest-grp-%')",
        );
        $conn->execute(
            'DELETE FROM groups_users WHERE group_id IN '
            . "(SELECT id FROM \"groups\" WHERE name LIKE 'zztest-grp-%')",
        );
        $conn->execute("DELETE FROM \"groups\" WHERE name LIKE 'zztest-grp-%'");
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzgroup.local'");
    }

    private function login(): void
    {
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_gadmin', 'email' => 'g@zzgroup.local']]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
    }

    private function makeGroup(string $suffix = ''): string
    {
        return (string)ConnectionManager::get('default')->execute(
            'INSERT INTO "groups" (name, description) VALUES (:n, :d) RETURNING id',
            ['n' => 'zztest-grp-' . $suffix . bin2hex(random_bytes(2)), 'd' => 'fixture'],
        )->fetch('assoc')['id'];
    }

    public function testIndexListsGroups(): void
    {
        $this->makeGroup('idx-');
        $this->login();
        $this->get('/admin/groups');

        $this->assertResponseOk();
        $this->assertResponseContains('zztest-grp-idx-');
        $this->assertResponseContains('scope="col"'); // A11y of the hand-built table
    }

    public function testAddCreatesGroupAndRedirects(): void
    {
        $this->login();
        $name = 'zztest-grp-add-' . bin2hex(random_bytes(2));
        $this->post('/admin/groups/add', ['name' => $name, 'description' => 'desc']);

        $this->assertResponseSuccess();
        $row = ConnectionManager::get('default')->execute(
            'SELECT description FROM "groups" WHERE name = :n',
            ['n' => $name],
        )->fetch('assoc');
        $this->assertNotFalse($row);
        $this->assertSame('desc', $row['description']);
        $this->assertRedirectContains('/admin/groups/view/');
    }

    public function testAddRejectsEmptyName(): void
    {
        $this->login();
        $before = $this->countGroups();
        $this->post('/admin/groups/add', ['name' => '   ', 'description' => 'x']);

        $this->assertResponseOk(); // no redirect: form with error
        $this->assertSame($before, $this->countGroups());
    }

    public function testViewUnknownRedirects(): void
    {
        $this->login();
        $this->get('/admin/groups/view/00000000-0000-0000-0000-000000000000');
        $this->assertRedirect(['action' => 'index']);
    }

    public function testMalformedIdsRedirectInsteadOf500(): void
    {
        // Malformed UUID in URL/form: UUID guard -> redirect instead of
        // 22P02 ("invalid input syntax for type uuid") -> 500.
        $gid = $this->makeGroup('uuid-');
        $this->login();

        $this->get('/admin/groups/view/garbage');
        $this->assertRedirect(['action' => 'index']);

        $this->post('/admin/groups/setActive/garbage/on');
        $this->assertRedirect(['action' => 'index']);

        $this->post('/admin/groups/setPermission/garbage', ['resource' => 'core::doc', 'can_read' => '1']);
        $this->assertRedirect(['action' => 'index']);

        $this->post('/admin/groups/removeMember/' . $gid . '/garbage');
        $this->assertRedirect(['action' => 'index']);

        // Malformed user ID in the form: no insert, no 500.
        $this->post('/admin/groups/addMember/' . $gid, ['user_id' => 'garbage']);
        $this->assertRedirect(['action' => 'view', $gid]);
        $this->assertSame(0, $this->memberCount($gid));
    }

    public function testViewRendersMembersAndCandidates(): void
    {
        $gid = $this->makeGroup('view-');
        ConnectionManager::get('default')->execute(
            'INSERT INTO groups_users (group_id, user_id) VALUES (:g, :u)',
            ['g' => $gid, 'u' => $this->memberId],
        );
        $this->login();
        $this->get('/admin/groups/view/' . $gid);

        $this->assertResponseOk();
        $this->assertResponseContains('zztest_member_'); // member listed
    }

    public function testSetActiveTogglesFlag(): void
    {
        $gid = $this->makeGroup('act-');
        $this->login();
        $this->post('/admin/groups/setActive/' . $gid . '/off');
        $this->assertRedirect(['action' => 'view', $gid]);
        $this->assertFalse((bool)$this->groupCol($gid, 'active'));

        $this->post('/admin/groups/setActive/' . $gid . '/on');
        $this->assertTrue((bool)$this->groupCol($gid, 'active'));
    }

    public function testAddAndRemoveMember(): void
    {
        $gid = $this->makeGroup('mem-');
        $this->login();

        $this->post('/admin/groups/addMember/' . $gid, ['user_id' => $this->memberId]);
        $this->assertSame(1, $this->memberCount($gid));

        $this->post('/admin/groups/removeMember/' . $gid . '/' . $this->memberId);
        $this->assertSame(0, $this->memberCount($gid));
    }

    public function testSetPermissionGrantsThenRevokes(): void
    {
        $gid = $this->makeGroup('perm-');
        $this->login();

        // Grant: BREAD read+browse on an object class.
        $this->post('/admin/groups/setPermission/' . $gid, [
            'resource' => 'core::doc',
            'resource_key' => '',
            'can_browse' => '1',
            'can_read' => '1',
        ]);
        $this->assertSame(1, $this->permCount($gid));

        // Revoke: all checkboxes off -> entry removed.
        $this->post('/admin/groups/setPermission/' . $gid, [
            'resource' => 'core::doc',
            'resource_key' => '',
        ]);
        $this->assertSame(0, $this->permCount($gid));
    }

    public function testSetPermissionRejectsInvalidResource(): void
    {
        $gid = $this->makeGroup('badperm-');
        $this->login();
        $this->post('/admin/groups/setPermission/' . $gid, ['resource' => 'noseparator']);
        $this->assertRedirect(['action' => 'view', $gid]);
        $this->assertSame(0, $this->permCount($gid));
    }

    private function countGroups(): int
    {
        return (int)ConnectionManager::get('default')
            ->execute("SELECT count(*) AS c FROM \"groups\" WHERE name LIKE 'zztest-grp-%'")
            ->fetch('assoc')['c'];
    }

    private function memberCount(string $gid): int
    {
        return (int)ConnectionManager::get('default')
            ->execute('SELECT count(*) AS c FROM groups_users WHERE group_id = :g', ['g' => $gid])
            ->fetch('assoc')['c'];
    }

    private function permCount(string $gid): int
    {
        return (int)ConnectionManager::get('default')
            ->execute('SELECT count(*) AS c FROM group_resource_permissions WHERE group_id = :g', ['g' => $gid])
            ->fetch('assoc')['c'];
    }

    /** @return mixed */
    private function groupCol(string $gid, string $col)
    {
        return ConnectionManager::get('default')
            ->execute('SELECT ' . $col . ' AS v FROM "groups" WHERE id = :id', ['id' => $gid])
            ->fetch('assoc')['v'];
    }
}
