<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Permission;

use App\Service\Permission\AdminGroupService;
use App\Service\Tenant\TenantService;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Admin-group bootstrap (ch. 25): ensure() creates the group once, grants full
 * BREAD + extra actions on every group-capable resource, and re-runs top up
 * newly registered resources without duplicating the group.
 */
class AdminGroupServiceTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    private const TENANT = TenantService::DEFAULT_TENANT_ID;
    private const GROUP = 'ZZTest Admins';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        // Tenant context: the permission rows inherit tenant_id from it.
        $conn->execute("SELECT set_config('app.current_tenant_id', :t, false)", ['t' => self::TENANT]);
        $this->seedResource('zzgrpinit', 'thing', '["publish"]');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        ConnectionManager::get('default')->execute("SELECT set_config('app.current_tenant_id', '', false)");
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute(
            'DELETE FROM group_resource_permissions WHERE group_id IN '
            . '(SELECT id FROM "groups" WHERE name = :n)',
            ['n' => self::GROUP],
        );
        $conn->execute(
            'DELETE FROM groups_users WHERE group_id IN (SELECT id FROM "groups" WHERE name = :n)',
            ['n' => self::GROUP],
        );
        $conn->execute('DELETE FROM "groups" WHERE name = :n', ['n' => self::GROUP]);
        $conn->execute("DELETE FROM resources WHERE module_key LIKE 'zzgrpinit%'");
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzgrpinit.local'");
    }

    private function seedResource(string $moduleKey, string $type, string $extraJson): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO resources (module_key, resource_type, resource_name, is_scoped, group_capable, extra_actions) '
            . 'VALUES (:m, :t, :n, false, true, CAST(:x AS jsonb)) ON CONFLICT DO NOTHING',
            ['m' => $moduleKey, 't' => $type, 'n' => "$moduleKey.$type", 'x' => $extraJson],
        );
    }

    public function testEnsureCreatesGroupWithFullGrantsAndIsIdempotent(): void
    {
        $service = new AdminGroupService();
        $first = $service->ensure(self::TENANT, self::GROUP);

        $this->assertTrue($first['created']);
        $this->assertGreaterThanOrEqual(1, $first['granted']);

        $conn = ConnectionManager::get('default');
        $perm = $conn->execute(
            'SELECT can_browse, can_read, can_add, can_edit, can_delete, extra_actions '
            . "FROM group_resource_permissions WHERE group_id = :g AND module_key = 'zzgrpinit' "
            . "AND resource_type = 'thing' AND resource_key IS NULL",
            ['g' => $first['id']],
        )->fetch('assoc');
        $this->assertNotFalse($perm, 'class-wide grant row exists');
        foreach (['can_browse', 'can_read', 'can_add', 'can_edit', 'can_delete'] as $col) {
            $this->assertTrue(in_array($perm[$col], [true, 't', '1', 1], true), "$col granted");
        }
        $extra = json_decode((string)$perm['extra_actions'], true);
        $this->assertTrue((bool)($extra['publish'] ?? false), 'declared extra action granted');

        // Re-run: no duplicate group; a resource registered AFTER the first run
        // (module installed later) gets topped up.
        $this->seedResource('zzgrpinit2', 'widget', '[]');
        $second = $service->ensure(self::TENANT, self::GROUP);
        $this->assertFalse($second['created']);
        $this->assertSame($first['id'], $second['id']);
        $count = (int)$conn->execute(
            'SELECT count(*) AS c FROM "groups" WHERE name = :n',
            ['n' => self::GROUP],
        )->fetch('assoc')['c'];
        $this->assertSame(1, $count);
        $topped = $conn->execute(
            "SELECT 1 FROM group_resource_permissions WHERE group_id = :g AND module_key = 'zzgrpinit2'",
            ['g' => $first['id']],
        )->fetch();
        $this->assertNotFalse($topped, 'later-registered resource topped up on re-run');
    }

    public function testAddUserIsIdempotent(): void
    {
        $conn = ConnectionManager::get('default');
        $service = new AdminGroupService();
        $group = $service->ensure(self::TENANT, self::GROUP);
        $userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zzgrpinit_' . bin2hex(random_bytes(3)), 'e' => bin2hex(random_bytes(3)) . '@zzgrpinit.local'],
        )->fetch('assoc')['id'];

        $service->addUser($group['id'], $userId);
        $service->addUser($group['id'], $userId); // no duplicate row

        $count = (int)$conn->execute(
            'SELECT count(*) AS c FROM groups_users WHERE group_id = :g AND user_id = :u',
            ['g' => $group['id'], 'u' => $userId],
        )->fetch('assoc')['c'];
        $this->assertSame(1, $count);
    }

    public function testGroupInitCommandRunsEndToEnd(): void
    {
        // CLI smoke: default tenant, custom name; the command sets and resets
        // the RLS tenant context itself.
        $this->exec('group_init --name "' . self::GROUP . '"');

        $this->assertExitSuccess();
        $this->assertOutputContains('Vollrechte');
        $row = ConnectionManager::get('default')->execute(
            'SELECT tenant_id FROM "groups" WHERE name = :n',
            ['n' => self::GROUP],
        )->fetch('assoc');
        $this->assertNotFalse($row);
        $this->assertSame(self::TENANT, (string)$row['tenant_id']);
    }
}
