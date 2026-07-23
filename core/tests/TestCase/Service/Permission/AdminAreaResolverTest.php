<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Permission;

use App\Service\Permission\AdminAreaResolver;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Unit test for the per-group admin-area resolver: union over the user's active
 * groups, the is_system wildcard (all areas), the "has any area" gate, per-tenant
 * last-admin counting, and the explicit tenant predicate that isolates tenants
 * even on the BYPASSRLS test connection.
 */
class AdminAreaResolverTest extends TestCase
{
    private \Cake\Database\Connection $conn;
    private string $tenantA;
    private string $tenantB;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        $this->conn = $conn;
        $this->cleanup();
        $this->tenantA = $this->makeTenant('zzresA');
        $this->tenantB = $this->makeTenant('zzresB');
        // Resolver reads core.current_tenant(); act as tenant A (as the request would).
        $this->setTenant($this->tenantA);
    }

    protected function tearDown(): void
    {
        $this->setTenant('');
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->conn->execute("DELETE FROM \"groups\" WHERE name LIKE 'zzres_%'");
        $this->conn->execute("DELETE FROM users WHERE email LIKE '%@zzres.local'");
        $this->conn->execute("DELETE FROM tenants WHERE key LIKE 'zzres%'");
    }

    private function setTenant(string $tenantId): void
    {
        $this->conn->execute("SELECT set_config('app.current_tenant_id', :t, false)", ['t' => $tenantId]);
    }

    private function makeTenant(string $key): string
    {
        return (string)$this->conn->execute(
            "INSERT INTO tenants (key, name) VALUES (:k || '_' || substr(md5(random()::text), 1, 6), 'T') RETURNING id",
            ['k' => $key],
        )->fetch('assoc')['id'];
    }

    private function makeUser(string $tenantId): string
    {
        return (string)$this->conn->execute(
            "INSERT INTO users (username, email, status, tenant_id) VALUES (:u, :e, 'active', :t) RETURNING id",
            [
                'u' => 'zzres_' . bin2hex(random_bytes(4)),
                'e' => bin2hex(random_bytes(4)) . '@zzres.local',
                't' => $tenantId,
            ],
        )->fetch('assoc')['id'];
    }

    /** Creates a group in $tenantId (optionally is_system) with the given areas, and adds $userId. */
    private function makeGroupWith(string $tenantId, string $userId, bool $isSystem, string ...$areas): string
    {
        $groupId = (string)$this->conn->execute(
            'INSERT INTO "groups" (name, tenant_id, is_system) VALUES (:n, :t, :s) RETURNING id',
            ['n' => 'zzres_' . bin2hex(random_bytes(5)), 't' => $tenantId, 's' => $isSystem ? 'true' : 'false'],
        )->fetch('assoc')['id'];
        foreach ($areas as $a) {
            $this->conn->execute(
                'INSERT INTO group_admin_areas (group_id, admin_area_key, tenant_id) VALUES (:g, :a, :t)',
                ['g' => $groupId, 'a' => $a, 't' => $tenantId],
            );
        }
        $this->conn->execute(
            'INSERT INTO groups_users (group_id, user_id, tenant_id) VALUES (:g, :u, :t)',
            ['g' => $groupId, 'u' => $userId, 't' => $tenantId],
        );

        return $groupId;
    }

    public function testAreasForUnionsActiveGroups(): void
    {
        $user = $this->makeUser($this->tenantA);
        $this->makeGroupWith($this->tenantA, $user, false, 'core_config');
        $this->makeGroupWith($this->tenantA, $user, false, 'user_group_admin', 'core_config');

        $areas = (new AdminAreaResolver())->areasFor($user);
        sort($areas);
        $this->assertSame(['core_config', 'user_group_admin'], $areas, 'dedup union over both groups');
    }

    public function testAdminGroupMemberGetsAllAreasWildcard(): void
    {
        $user = $this->makeUser($this->tenantA);
        $this->makeGroupWith($this->tenantA, $user, true); // is_system, NO explicit area rows

        $areas = (new AdminAreaResolver())->areasFor($user);
        $catalog = (int)$this->conn->execute('SELECT count(*) AS c FROM admin_areas')->fetch('assoc')['c'];
        $this->assertCount($catalog, $areas, 'is_system member resolves to the WHOLE catalog');
        $this->assertContains('core_config', $areas);
    }

    public function testInactiveGroupDoesNotGrant(): void
    {
        $user = $this->makeUser($this->tenantA);
        $gid = $this->makeGroupWith($this->tenantA, $user, false, 'core_config');
        $this->conn->execute('UPDATE "groups" SET active = false WHERE id = :g', ['g' => $gid]);

        $this->assertSame([], (new AdminAreaResolver())->areasFor($user), 'an inactive group grants nothing');
    }

    public function testHasAny(): void
    {
        $withArea = $this->makeUser($this->tenantA);
        $this->makeGroupWith($this->tenantA, $withArea, false, 'core_config');
        $withoutArea = $this->makeUser($this->tenantA);
        $this->makeGroupWith($this->tenantA, $withoutArea, false); // group, but no areas

        $resolver = new AdminAreaResolver();
        $this->assertTrue($resolver->hasAny($withArea));
        $this->assertFalse($resolver->hasAny($withoutArea));
    }

    public function testTenantIsolationViaExplicitPredicate(): void
    {
        // The user's group lives in tenant B; acting as tenant A must resolve nothing —
        // proving the explicit tenant predicate isolates even under BYPASSRLS.
        $user = $this->makeUser($this->tenantB);
        $this->makeGroupWith($this->tenantB, $user, false, 'core_config');

        // Context is tenant A (setUp).
        $this->assertSame([], (new AdminAreaResolver())->areasFor($user), 'no cross-tenant leak');
        // Switch to tenant B -> now visible.
        $this->setTenant($this->tenantB);
        $this->assertSame(['core_config'], (new AdminAreaResolver())->areasFor($user));
    }

    public function testActiveHoldersOfIsPerTenantAndExcludes(): void
    {
        $a1 = $this->makeUser($this->tenantA);
        $a2 = $this->makeUser($this->tenantA);
        $this->makeGroupWith($this->tenantA, $a1, false, 'user_group_admin');
        $this->makeGroupWith($this->tenantA, $a2, false, 'user_group_admin');
        // Another tenant's holder must NOT count toward tenant A.
        $b1 = $this->makeUser($this->tenantB);
        $this->makeGroupWith($this->tenantB, $b1, false, 'user_group_admin');

        $resolver = new AdminAreaResolver();
        $this->assertSame(2, $resolver->activeHoldersOf('user_group_admin'), 'both tenant-A holders, tenant-B excluded');
        $this->assertSame(1, $resolver->activeHoldersOf('user_group_admin', $a1), 'excluding a1 leaves a2');
    }
}
