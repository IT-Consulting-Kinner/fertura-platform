<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Permission;

use App\Service\Permission\PermissionService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Tests the BREAD permission check with **deny rules** (deny-wins): a denial
 * overrides any grant — even across groups.
 */
class PermissionServiceTest extends TestCase
{
    private string $userId;
    private string $g1;
    private string $g2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        $this->userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_perm_' . bin2hex(random_bytes(3)), 'e' => 'perm_' . bin2hex(random_bytes(3)) . '@zzperm.local'],
        )->fetch('assoc')['id'];
        $this->g1 = $this->makeGroup('zztest_grp_a');
        $this->g2 = $this->makeGroup('zztest_grp_b');
        foreach ([$this->g1, $this->g2] as $g) {
            $conn->execute('INSERT INTO groups_users (group_id, user_id) VALUES (:g, :u)', ['g' => $g, 'u' => $this->userId]);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function makeGroup(string $name): string
    {
        return (string)ConnectionManager::get('default')->execute(
            'INSERT INTO "groups" (name, active) VALUES (:n, true) RETURNING id',
            ['n' => $name . '_' . bin2hex(random_bytes(2))],
        )->fetch('assoc')['id'];
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute("DELETE FROM group_resource_permissions WHERE module_key = 'zzmod'");
        $conn->execute('DELETE FROM groups_users gu USING "groups" g WHERE gu.group_id = g.id AND g.name LIKE \'zztest_grp_%\'');
        $conn->execute('DELETE FROM "groups" WHERE name LIKE \'zztest_grp_%\'');
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzperm.local'");
    }

    public function testDenyOverridesAllowOnSameGroup(): void
    {
        $p = new PermissionService();
        $p->grant($this->g1, 'zzmod', 'thing', null, ['read' => true, 'edit' => true]);
        $this->assertTrue($p->canPerform($this->userId, 'zzmod', 'thing', null, 'read'));

        $p->deny($this->g1, 'zzmod', 'thing', null, ['read' => true]);
        $this->assertFalse($p->canPerform($this->userId, 'zzmod', 'thing', null, 'read'), 'deny-wins');
        $this->assertTrue($p->canPerform($this->userId, 'zzmod', 'thing', null, 'edit'), 'edit bleibt erlaubt');
    }

    public function testDenyInOneGroupOverridesAllowInAnother(): void
    {
        $p = new PermissionService();
        $p->grant($this->g1, 'zzmod', 'thing2', null, ['read' => true]);
        $p->deny($this->g2, 'zzmod', 'thing2', null, ['read' => true]);
        $this->assertFalse(
            $p->canPerform($this->userId, 'zzmod', 'thing2', null, 'read'),
            'Deny in einer Gruppe sticht Allow in einer anderen',
        );
    }

    public function testExtraActionDeny(): void
    {
        $p = new PermissionService();
        $p->grant($this->g1, 'zzmod', 'thing3', null, [], ['export' => true]);
        $this->assertTrue($p->canPerform($this->userId, 'zzmod', 'thing3', null, 'export'));

        $p->deny($this->g1, 'zzmod', 'thing3', null, [], ['export' => true]);
        $this->assertFalse($p->canPerform($this->userId, 'zzmod', 'thing3', null, 'export'));
    }
}
