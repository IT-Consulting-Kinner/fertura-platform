<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use App\Service\Tenant\TenantService;
use App\Test\TestCase\AdminAreaSeedTrait;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integration test for the tenants admin GUI: renders for a core_config admin
 * (verifies controller + template + the module UI kit in a real render) and creates.
 */
class TenantsControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use AdminAreaSeedTrait;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        foreach ([['core_config', 'Core', 60], ['user_group_admin', 'Users', 10], ['tenant_modules', 'Modules', 25]] as [$key, $label, $sort]) {
            // Areas the onboarded tenant admin ends up with via the Administrators
            // group (FK target of group_admin_areas); in prod the area set is seeded.
            $conn->execute(
                'INSERT INTO admin_areas (area_key, label, sort_order) VALUES (:k, :l, :s) ON CONFLICT (area_key) DO NOTHING',
                ['k' => $key, 'l' => $label, 's' => $sort],
            );
        }
        $this->userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_tadmin_' . bin2hex(random_bytes(3)), 'e' => 'tadmin_' . bin2hex(random_bytes(3)) . '@zztenant.local'],
        )->fetch('assoc')['id'];
        $this->grantAdminAreas($this->userId, 'core_config');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        // Children of the test users first (no ON DELETE CASCADE), then users/tenants.
        $conn->execute(
            'DELETE FROM password_reset_tokens WHERE user_id IN '
            . "(SELECT id FROM users WHERE email LIKE '%@zztenant.local')",
        );
        // Groups seeded for the acting admin (zzseedarea_*) + the Administrators group
        // createAdmin builds in a test tenant — removed BEFORE their tenants (groups.
        // tenant_id FK). group_admin_areas / resource grants / memberships cascade.
        $conn->execute(
            'DELETE FROM "groups" WHERE name LIKE \'zzseedarea_%\' '
            . "OR tenant_id IN (SELECT id FROM tenants WHERE key LIKE 'zztest-%')",
        );
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zztenant.local'");
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zztest-%'");
    }

    private function login(): void
    {
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_tadmin', 'email' => 't@zztenant.local']]);
    }

    public function testIndexRendersWithUiKit(): void
    {
        $this->login();
        $this->get('/admin/tenants');

        $this->assertResponseOk();
        $this->assertResponseContains('Default'); // default tenant in the list
        $this->assertResponseContains('sort=name'); // sortable header (UiKit::sortHeader)
        $this->assertResponseContains('Create tenant'); // form (UiKit::fields + button), i18n en_US
        // A11y: skip link, main landmark, active nav entry, scoped column headers.
        $this->assertResponseContains('Skip to main content');
        $this->assertResponseContains('id="main"');
        $this->assertResponseContains('aria-current="page"');
        $this->assertResponseContains('scope="col"');
    }

    public function testHandRolledAdminTableHasScopedHeaders(): void
    {
        // Guard for the systematic A11y rollout: *hand-built* admin tables too
        // (not only UiKit-generated ones) carry scope="col" on the column headers.
        $this->login();
        $this->get('/admin/config');

        $this->assertResponseOk();
        $this->assertResponseContains('<th scope="col">'); // Config/index.php is hand-built
    }

    public function testAddCreatesTenant(): void
    {
        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $key = 'zztest-ctrl-' . bin2hex(random_bytes(2));

        $this->post('/admin/tenants/add', ['key' => $key, 'name' => 'Controller Tenant']);

        $this->assertRedirect(['action' => 'index']);
        $row = ConnectionManager::get('default')->execute(
            'SELECT name FROM tenants WHERE key = :k',
            ['k' => $key],
        )->fetch('assoc');
        $this->assertNotFalse($row);
        $this->assertSame('Controller Tenant', $row['name']);
    }

    public function testAddRejectsDuplicateKeyWithoutError(): void
    {
        // A duplicate tenant key must warn + re-render (200), NOT surface the DB
        // unique index (uq_tenants_key) as a 500 / aborted request tx.
        $conn = ConnectionManager::get('default');
        $key = 'zztest-dupkey-' . bin2hex(random_bytes(2));
        $conn->execute("INSERT INTO tenants (key, name) VALUES (:k, 'Existing')", ['k' => $key]);
        $before = (int)$conn->execute('SELECT count(*) c FROM tenants WHERE key = :k', ['k' => $key])->fetch('assoc')['c'];
        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/admin/tenants/add', ['key' => $key, 'name' => 'Dup Attempt']);

        $this->assertResponseOk(); // no 500, no redirect: list re-rendered with the warning
        $this->assertSame(
            $before,
            (int)$conn->execute('SELECT count(*) c FROM tenants WHERE key = :k', ['k' => $key])->fetch('assoc')['c'],
            'no second tenant with the same key',
        );
    }

    public function testAddRejectsCaseVariantDuplicateKey(): void
    {
        // TenantService lowercases the key before insert, so a case-variant of an
        // existing key must still be caught in-place (200 + warning), not slip past
        // the pre-check into a 23505.
        $conn = ConnectionManager::get('default');
        $key = 'zztest-case-' . bin2hex(random_bytes(2));
        $conn->execute('INSERT INTO tenants (key, name) VALUES (:k, :n)', ['k' => $key, 'n' => 'Lower']);
        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/admin/tenants/add', ['key' => strtoupper($key), 'name' => 'Upper Attempt']);

        $this->assertResponseOk(); // caught in-place, no 303 to an empty form, no 500
        $this->assertSame(
            1,
            (int)$conn->execute('SELECT count(*) c FROM tenants WHERE lower(key) = lower(:k)', ['k' => $key])->fetch('assoc')['c'],
            'no case-variant duplicate created',
        );
    }

    public function testAssignUserToTenant(): void
    {
        $conn = ConnectionManager::get('default');
        $tid = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zztest-assign', 'Assign') RETURNING id",
        )->fetch('assoc')['id'];
        $uid = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_assignee_' . bin2hex(random_bytes(3)), 'e' => 'assignee@zztenant.local'],
        )->fetch('assoc')['id'];

        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/admin/tenants/assign', ['email' => 'assignee@zztenant.local', 'tenant_id' => $tid]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertSame($tid, (new TenantService())->tenantIdForUser($uid));
    }

    public function testMalformedTenantIdsFailGracefully(): void
    {
        // Malformed UUID in the form: UUID guard at the service boundary
        // (TenantService) -> error flash + redirect instead of 22P02 -> 500.
        ConnectionManager::get('default')->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active')",
            ['u' => 'zztest_badassign_' . bin2hex(random_bytes(3)), 'e' => 'badassign@zztenant.local'],
        );

        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/admin/tenants/assign', ['email' => 'badassign@zztenant.local', 'tenant_id' => 'garbage']);
        $this->assertRedirect(['action' => 'index']);

        $this->post('/admin/tenants/bulk', ['op' => 'suspend', 'ids' => ['garbage']]);
        $this->assertRedirect(['action' => 'index']);
    }

    public function testCreateAdminCreatesTenantUserWithGrantAndInvite(): void
    {
        $conn = ConnectionManager::get('default');
        $tid = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zztest-onb', 'Onboard') RETURNING id",
        )->fetch('assoc')['id'];

        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $email = 'newadmin_' . bin2hex(random_bytes(3)) . '@zztenant.local';
        $this->post('/admin/tenants/create-admin', [
            'tenant_id' => $tid,
            'username' => 'zztest_newadmin_' . bin2hex(random_bytes(3)),
            'email' => $email,
        ]);
        $this->assertRedirect(['action' => 'index']);

        // The new user lives in the TARGET tenant, is invited, and is a member of that
        // tenant's Administrators group; an invitation token was created.
        $row = $conn->execute(
            'SELECT id, tenant_id, status FROM users WHERE lower(email) = lower(:e)',
            ['e' => $email],
        )->fetch('assoc');
        $this->assertNotFalse($row);
        $this->assertSame($tid, $row['tenant_id']);
        $this->assertSame('invited', $row['status']);
        // Admin access comes from membership in the TARGET tenant's Administrators
        // (is_system) group, which holds every admin area by the wildcard rule.
        $this->assertNotFalse(
            $conn->execute(
                'SELECT 1 FROM groups_users gu JOIN "groups" g ON g.id = gu.group_id '
                . 'WHERE gu.user_id = :u AND g.is_system AND g.active AND g.tenant_id = :t',
                ['u' => $row['id'], 't' => $tid],
            )->fetch(),
            'new tenant admin is a member of the target tenant Administrators group',
        );
        $this->assertSame(
            1,
            (int)$conn->execute(
                "SELECT count(*) AS c FROM password_reset_tokens WHERE user_id = :u AND purpose = 'invite'",
                ['u' => $row['id']],
            )->fetch('assoc')['c'],
        );
    }

    public function testCreateAdminRejectsDuplicateEmail(): void
    {
        // Onboarding a tenant admin with an email that already exists (globally,
        // any case) fails cleanly (error flash + redirect, no 500) and creates no
        // second user — the global email uniqueness rule applies cross-tenant too.
        $conn = ConnectionManager::get('default');
        $tid = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zztest-dupadmin', 'DupAdmin') RETURNING id",
        )->fetch('assoc')['id'];
        $email = 'existing_' . bin2hex(random_bytes(3)) . '@zztenant.local';
        $conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active')",
            ['u' => 'zztest_existing_' . bin2hex(random_bytes(3)), 'e' => $email],
        );

        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/admin/tenants/create-admin', [
            'tenant_id' => $tid,
            'username' => 'zztest_dupadmin_' . bin2hex(random_bytes(3)),
            'email' => strtoupper($email),
        ]);
        $this->assertRedirect(['action' => 'index']);

        // Still exactly one user with that email, and it was NOT moved into the
        // onboarding target tenant (i.e. the existing row was left untouched).
        $rows = $conn->execute(
            'SELECT tenant_id FROM users WHERE lower(email) = lower(:e)',
            ['e' => $email],
        )->fetchAll('assoc');
        $this->assertCount(1, $rows, 'No second user must be created for a duplicate email.');
        $this->assertNotSame($tid, $rows[0]['tenant_id']);
    }

    public function testCreateAdminRefusesOperatorTenant(): void
    {
        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $email = 'reject_' . bin2hex(random_bytes(3)) . '@zztenant.local';
        // The operator tenant (default) is not a valid onboarding target -> no user.
        $this->post('/admin/tenants/create-admin', [
            'tenant_id' => '00000000-0000-0000-0000-000000000001',
            'username' => 'zztest_reject',
            'email' => $email,
        ]);
        $this->assertRedirect(['action' => 'index']);
        $this->assertFalse(
            ConnectionManager::get('default')
                ->execute('SELECT 1 FROM users WHERE lower(email) = lower(:e)', ['e' => $email])
                ->fetch(),
        );
    }

    public function testBulkSuspendAndActivate(): void
    {
        $conn = ConnectionManager::get('default');
        $id = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zztest-bulk', 'Bulk') RETURNING id",
        )->fetch('assoc')['id'];

        $this->login();
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/admin/tenants/bulk', ['op' => 'suspend', 'ids' => [$id]]);
        $this->assertRedirect(['action' => 'index']);
        $this->assertFalse((bool)$conn->execute('SELECT active FROM tenants WHERE id = :id', ['id' => $id])->fetch('assoc')['active']);

        $this->post('/admin/tenants/bulk', ['op' => 'activate', 'ids' => [$id]]);
        $this->assertTrue((bool)$conn->execute('SELECT active FROM tenants WHERE id = :id', ['id' => $id])->fetch('assoc')['active']);
    }
}
