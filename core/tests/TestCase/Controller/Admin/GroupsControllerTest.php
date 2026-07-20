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
        $conn->execute("DELETE FROM resources WHERE module_key = 'zzgrpui'");
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzgroup.local'");
        // Foreign tenants from the cross-tenant isolation test (after their groups/users).
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zzt_grp_other_%'");
    }

    private function login(): void
    {
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_gadmin', 'email' => 'g@zzgroup.local']]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
    }

    private function makeGroup(string $suffix = ''): string
    {
        // Stamp the default tenant explicitly: the raw fixture insert runs outside a
        // request, where groups.tenant_id's DEFAULT core.current_tenant() is NULL — the
        // GUI (which creates groups inside the request) always lands the acting admin's
        // tenant, which here is the default tenant the gadmin belongs to.
        return (string)ConnectionManager::get('default')->execute(
            'INSERT INTO "groups" (name, description, tenant_id) VALUES (:n, :d, :t) RETURNING id',
            [
                'n' => 'zztest-grp-' . $suffix . bin2hex(random_bytes(2)),
                'd' => 'fixture',
                't' => '00000000-0000-0000-0000-000000000001',
            ],
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

        $this->post('/admin/groups/savePermissions/garbage', ['perm' => []]);
        $this->assertRedirect(['action' => 'index']);

        $this->post('/admin/groups/removeMember/' . $gid . '/garbage');
        $this->assertRedirect(['action' => 'index']);

        // Malformed user ID in the form: no insert, no 500.
        $this->post('/admin/groups/addMember/' . $gid, ['user_id' => 'garbage']);
        $this->assertRedirect(['action' => 'view', $gid]);
        $this->assertSame(0, $this->memberCount($gid));
    }

    public function testAddMemberRejectsCrossTenantUserAndGroup(): void
    {
        $conn = ConnectionManager::get('default');
        // A foreign tenant with its own user + group.
        $otherTenant = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zzt_grp_other_' || substr(md5(random()::text), 1, 8), 'Other') RETURNING id",
        )->fetch('assoc')['id'];
        $foreignUser = (string)$conn->execute(
            "INSERT INTO users (username, email, status, tenant_id) VALUES (:u, :e, 'active', :t) RETURNING id",
            ['u' => 'zztest_fuser_' . bin2hex(random_bytes(3)), 'e' => 'fuser_' . bin2hex(random_bytes(3)) . '@zzgroup.local', 't' => $otherTenant],
        )->fetch('assoc')['id'];
        $foreignGroup = (string)$conn->execute(
            'INSERT INTO "groups" (name, description, tenant_id) VALUES (:n, :d, :t) RETURNING id',
            ['n' => 'zztest-grp-foreign-' . bin2hex(random_bytes(2)), 'd' => 'f', 't' => $otherTenant],
        )->fetch('assoc')['id'];

        $ownGroup = $this->makeGroup('own-'); // default tenant (the gadmin's)
        $this->login();

        // 1. The HIGH leak: a FOREIGN user must NOT be addable to the OWN group.
        $this->post('/admin/groups/addMember/' . $ownGroup, ['user_id' => $foreignUser]);
        $this->assertSame(0, $this->memberCount($ownGroup));
        $this->assertFalse(
            $conn->execute('SELECT 1 FROM groups_users WHERE user_id = :u', ['u' => $foreignUser])->fetch(),
        );

        // 2. The own user must NOT be addable to a FOREIGN group (group not in tenant).
        $this->post('/admin/groups/addMember/' . $foreignGroup, ['user_id' => $this->memberId]);
        $this->assertSame(0, $this->memberCount($foreignGroup));

        $conn->execute('DELETE FROM groups_users WHERE group_id = :g', ['g' => $foreignGroup]);
        $conn->execute('DELETE FROM "groups" WHERE id = :g', ['g' => $foreignGroup]);
        $conn->execute('DELETE FROM users WHERE id = :u', ['u' => $foreignUser]);
        $conn->execute('DELETE FROM tenants WHERE id = :t', ['t' => $otherTenant]);
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

    /** Registers a group-capable test resource (cleaned up via zzgrpui prefix). */
    private function makeResource(string $type = 'thing', string $extraJson = '["publish"]'): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO resources (module_key, resource_type, resource_name, is_scoped, group_capable, extra_actions) '
            . "VALUES ('zzgrpui', :t, :n, true, true, CAST(:x AS jsonb)) ON CONFLICT DO NOTHING",
            ['t' => $type, 'n' => 'zzgrpui.' . $type, 'x' => $extraJson],
        );
    }

    public function testSavePermissionsGrantsThenRevokesViaCheckboxState(): void
    {
        // The editor posts the whole checkbox matrix: checked = grant (class-
        // wide), an entirely unchecked resource = revoke.
        $this->makeResource();
        $gid = $this->makeGroup('perm-');
        $this->login();

        $this->post('/admin/groups/savePermissions/' . $gid, [
            'perm' => ['zzgrpui::thing' => ['browse' => '1', 'read' => '1', 'x' => ['publish' => '1']]],
        ]);
        $this->assertSame(1, $this->permCount($gid));
        $row = ConnectionManager::get('default')->execute(
            'SELECT can_browse, can_read, can_delete, resource_key, extra_actions FROM group_resource_permissions WHERE group_id = :g',
            ['g' => $gid],
        )->fetch('assoc');
        $this->assertTrue(in_array($row['can_browse'], [true, 't', '1', 1], true));
        $this->assertFalse(in_array($row['can_delete'], [true, 't', '1', 1], true));
        $this->assertNull($row['resource_key']); // GUI grants the CLASS only
        $this->assertTrue((bool)(json_decode((string)$row['extra_actions'], true)['publish'] ?? false));

        // Revoke: same save with the resource unchecked -> row removed.
        $this->post('/admin/groups/savePermissions/' . $gid, ['perm' => []]);
        $this->assertSame(0, $this->permCount($gid));
    }

    public function testSavePermissionsIgnoresForgedResourceAndUndeclaredExtra(): void
    {
        // Fail-closed: only REGISTERED group-capable resources and their
        // DECLARED extra actions are processed — forged form keys do nothing.
        $this->makeResource();
        $gid = $this->makeGroup('badperm-');
        $this->login();

        $this->post('/admin/groups/savePermissions/' . $gid, [
            'perm' => [
                'evil::thing' => ['browse' => '1'], // unregistered resource
                'zzgrpui::thing' => ['x' => ['undeclared_action' => '1']], // undeclared extra
            ],
        ]);
        $this->assertRedirect(['action' => 'view', $gid]);
        $this->assertSame(0, $this->permCount($gid));
    }

    public function testSavePermissionsRejectsCrossTenantGroup(): void
    {
        // Explicit tenant guard (dual-role convention): savePermissions on a
        // FOREIGN tenant's group must be treated like an unknown group.
        $conn = ConnectionManager::get('default');
        $otherTenant = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zzt_grp_other_' || substr(md5(random()::text), 1, 8), 'Other') RETURNING id",
        )->fetch('assoc')['id'];
        $foreignGroup = (string)$conn->execute(
            'INSERT INTO "groups" (name, description, tenant_id) VALUES (:n, :d, :t) RETURNING id',
            ['n' => 'zztest-grp-xperm-' . bin2hex(random_bytes(2)), 'd' => 'f', 't' => $otherTenant],
        )->fetch('assoc')['id'];
        $this->makeResource();
        $this->login();

        $this->post('/admin/groups/savePermissions/' . $foreignGroup, [
            'perm' => ['zzgrpui::thing' => ['browse' => '1']],
        ]);

        $this->assertRedirect(['action' => 'index']); // treated as unknown
        $this->assertSame(0, $this->permCount($foreignGroup));
        $conn->execute('DELETE FROM "groups" WHERE id = :g', ['g' => $foreignGroup]);
        $conn->execute('DELETE FROM tenants WHERE id = :t', ['t' => $otherTenant]);
    }

    public function testSavePermissionsPreservesObjectRowsAndUndeclaredExtras(): void
    {
        // Review findings: a Save click must not destroy CLI-managed state —
        // neither object-level rows (resource_key set) nor stored extra actions
        // OUTSIDE the resource's declaration (invisible in the checkbox editor).
        $this->makeResource();
        $gid = $this->makeGroup('cli-');
        $conn = ConnectionManager::get('default');
        $conn->execute(
            'INSERT INTO group_resource_permissions (group_id, module_key, resource_type, can_read, extra_actions) '
            . "VALUES (:g, 'zzgrpui', 'thing', true, CAST('{\"cli_special\": true}' AS jsonb))",
            ['g' => $gid],
        );
        $conn->execute(
            'INSERT INTO group_resource_permissions (group_id, module_key, resource_type, resource_key, can_read) '
            . "VALUES (:g, 'zzgrpui', 'thing', 'OBJ-1', true)",
            ['g' => $gid],
        );
        $this->login();

        // Save with browse checked (read unchecked): the undeclared CLI extra
        // must survive, the object row must stay untouched.
        $this->post('/admin/groups/savePermissions/' . $gid, [
            'perm' => ['zzgrpui::thing' => ['browse' => '1']],
        ]);

        $class = $conn->execute(
            'SELECT can_browse, can_read, extra_actions FROM group_resource_permissions '
            . 'WHERE group_id = :g AND resource_key IS NULL',
            ['g' => $gid],
        )->fetch('assoc');
        $this->assertTrue(in_array($class['can_browse'], [true, 't', '1', 1], true));
        $this->assertFalse(in_array($class['can_read'], [true, 't', '1', 1], true));
        $this->assertTrue(
            (bool)(json_decode((string)$class['extra_actions'], true)['cli_special'] ?? false),
            'undeclared CLI extra survives the GUI save',
        );
        $obj = $conn->execute(
            "SELECT 1 FROM group_resource_permissions WHERE group_id = :g AND resource_key = 'OBJ-1'",
            ['g' => $gid],
        )->fetch();
        $this->assertNotFalse($obj, 'object-level row survives the blanket save');
    }

    public function testSavePermissionsKeepsDenyRowOnAllUncheckedSave(): void
    {
        // Deny-wins: deleting a deny-carrying row would ACT AS A GRANT (the
        // deny disappears, another group's allow wins again) — an all-unchecked
        // save clears the allow side only and keeps the row.
        $this->makeResource();
        $gid = $this->makeGroup('deny-');
        $conn = ConnectionManager::get('default');
        $conn->execute(
            'INSERT INTO group_resource_permissions (group_id, module_key, resource_type, can_browse, deny_read) '
            . "VALUES (:g, 'zzgrpui', 'thing', true, true)",
            ['g' => $gid],
        );
        $this->login();

        $this->post('/admin/groups/savePermissions/' . $gid, ['perm' => []]);

        $row = $conn->execute(
            'SELECT can_browse, deny_read FROM group_resource_permissions WHERE group_id = :g AND resource_key IS NULL',
            ['g' => $gid],
        )->fetch('assoc');
        $this->assertNotFalse($row, 'deny-carrying row survives');
        $this->assertFalse(in_array($row['can_browse'], [true, 't', '1', 1], true), 'allow side cleared');
        $this->assertTrue(in_array($row['deny_read'], [true, 't', '1', 1], true), 'deny preserved');
    }

    public function testViewRendersEditorInBreadOrderWithPrefilledState(): void
    {
        // The checkbox row follows the BREAD order (Browse, Read, Edit, Add,
        // Delete — not the old B-R-A-E-D), grants are prefilled as checked, the
        // module accordion groups the resources, and the object-key input is gone.
        $this->makeResource();
        $gid = $this->makeGroup('render-');
        ConnectionManager::get('default')->execute(
            'INSERT INTO group_resource_permissions (group_id, module_key, resource_type, can_browse, can_read) '
            . "VALUES (:g, 'zzgrpui', 'thing', true, true)",
            ['g' => $gid],
        );
        $this->login();
        $this->get('/admin/groups/view/' . $gid);

        $this->assertResponseOk();
        $body = (string)$this->_response->getBody();
        // BREAD order: the checkbox ids appear as browse < read < edit < add < delete.
        $pos = [];
        foreach (['browse', 'read', 'edit', 'add', 'delete'] as $a) {
            $p = strpos($body, 'id="zzgrpui__thing_' . $a . '"');
            $this->assertNotFalse($p, "checkbox $a rendered");
            $pos[] = $p;
        }
        $sorted = $pos;
        sort($sorted);
        $this->assertSame($sorted, $pos, 'checkboxes follow BREAD order');
        // Prefill: granted browse is checked, ungranted delete is not.
        $this->assertMatchesRegularExpression('/id="zzgrpui__thing_browse"[^>]*checked/', $body);
        $this->assertDoesNotMatchRegularExpression('/id="zzgrpui__thing_delete"[^>]*checked/', $body);
        // Accordion per module + scoped badge, and NO object-key field anymore.
        $this->assertResponseContains('id="perm-zzgrpui"');
        $this->assertResponseContains('scoped');
        $this->assertResponseNotContains('name="resource_key"');
    }

    /** Creates a PROTECTED system group (is_system=true) in the default tenant. */
    private function makeSystemGroup(string $suffix = 'sys-'): string
    {
        return (string)ConnectionManager::get('default')->execute(
            'INSERT INTO "groups" (name, description, tenant_id, is_system) VALUES (:n, :d, :t, true) RETURNING id',
            [
                'n' => 'zztest-grp-' . $suffix . bin2hex(random_bytes(2)),
                'd' => 'system fixture',
                't' => '00000000-0000-0000-0000-000000000001',
            ],
        )->fetch('assoc')['id'];
    }

    public function testSystemGroupCannotBeDeactivated(): void
    {
        $gid = $this->makeSystemGroup('nodeact-');
        $this->login();
        $this->post('/admin/groups/setActive/' . $gid . '/off');

        $this->assertRedirect(['action' => 'view', $gid]);
        // The server-side guard rejected it — the group stays active.
        $this->assertTrue((bool)$this->groupCol($gid, 'active'), 'system group stays active');
    }

    public function testSystemGroupPermissionsCannotBeEdited(): void
    {
        $this->makeResource();
        $gid = $this->makeSystemGroup('noperm-');
        $this->login();
        $this->post('/admin/groups/savePermissions/' . $gid, [
            'perm' => ['zzgrpui::thing' => ['browse' => '1', 'read' => '1']],
        ]);

        $this->assertRedirect(['action' => 'view', $gid]);
        // The guard rejected the edit — no permission row was written.
        $this->assertSame(0, $this->permCount($gid), 'no rights written for the system group');
    }

    public function testSystemGroupViewDisablesEditing(): void
    {
        $this->makeResource();
        $gid = $this->makeSystemGroup('sysview-');
        $this->login();
        $this->get('/admin/groups/view/' . $gid);

        $this->assertResponseOk();
        $body = (string)$this->_response->getBody();
        // The permission editor still renders (resource present) but its checkboxes
        // are disabled and the Save button is gone (a non-system group has it).
        $this->assertResponseContains('id="perm-zzgrpui"');
        $this->assertMatchesRegularExpression('/id="zzgrpui__thing_browse"[^>]*disabled/', $body);
        // The deactivate postLink is not offered for a system group.
        $this->assertResponseNotContains('/admin/groups/setActive/' . $gid);
    }

    public function testOptionsReturnsActiveGroupsAsJson(): void
    {
        $gid = $this->makeGroup('opt-');
        $name = (string)$this->groupCol($gid, 'name');
        $this->login();
        $this->get('/admin/groups/options');

        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $data = json_decode((string)$this->_response->getBody(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('options', $data);
        $this->assertContains($gid, array_column($data['options'], 'value'), 'group id present');
        $this->assertContains($name, array_column($data['options'], 'label'), 'group name present');
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
