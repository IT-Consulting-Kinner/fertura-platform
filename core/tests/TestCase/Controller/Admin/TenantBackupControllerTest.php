<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integration test for the tenant-facing backup GUI (tenant-backup design §5,
 * Increment 6a): a tenant admin lists + creates + downloads backups of THEIR OWN
 * tenant; the area is tenant-scoped and RLS isolates one tenant's backups from
 * another's. Each test runs in a FRESH tenant so the scoped export is small.
 */
class TenantBackupControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private string $userId = '';
    private string $tenantId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "INSERT INTO admin_areas (area_key, label, sort_order) VALUES ('tenant_backup', 'Backup', 30) "
            . 'ON CONFLICT (area_key) DO NOTHING',
        );
        $this->tenantId = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zztbk-' || substr(md5(random()::text), 1, 8), 'ZZ Backup') RETURNING id",
        )->fetch('assoc')['id'];
        $this->userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status, tenant_id) VALUES (:u, :e, 'active', :t) RETURNING id",
            [
                'u' => 'zztbk_' . bin2hex(random_bytes(3)),
                'e' => 'tbk_' . bin2hex(random_bytes(3)) . '@zztbk.local',
                't' => $this->tenantId,
            ],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $this->userId, 'a' => 'tenant_backup'],
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        // Remove the archive files of the test tenants, then the DB rows (tenant_backups
        // cascades with the tenant).
        foreach ($conn->execute("SELECT id FROM tenants WHERE key LIKE 'zztbk-%'")->fetchAll('assoc') as $t) {
            $this->rrmdir(ROOT . '/backups/tenant/' . (string)$t['id']);
        }
        $conn->execute(
            'DELETE FROM user_admin_areas WHERE user_id IN '
            . "(SELECT id FROM users WHERE email LIKE '%@zztbk.local')",
        );
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zztbk.local'");
        // Tenant-scoped child rows referencing the test tenants (no ON DELETE CASCADE).
        $conn->execute("DELETE FROM automation_rules WHERE name LIKE 'zzrest%'");
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zztbk-%'");
    }

    private function login(string $userId): void
    {
        $this->session(['Auth' => ['id' => $userId, 'username' => 'zztbk', 'email' => 't@zztbk.local']]);
    }

    public function testIndexRenders(): void
    {
        $this->login($this->userId);
        $this->get('/admin/tenant-backup');
        $this->assertResponseOk();
        $this->assertResponseContains('/admin/tenant-backup/create'); // create form present
    }

    public function testCreateMakesRowFileAndAudit(): void
    {
        $this->login($this->userId);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $conn = ConnectionManager::get('default');

        $this->post('/admin/tenant-backup/create', ['note' => 'zz-note']);
        $this->assertRedirect(['action' => 'index']);

        $row = $conn->execute(
            'SELECT id, storage_path, row_counts FROM tenant_backups WHERE tenant_id = :t',
            ['t' => $this->tenantId],
        )->fetch('assoc');
        $this->assertNotFalse($row, 'a tenant_backups row exists');
        $this->assertFileExists((string)$row['storage_path'], 'the archive file was written');
        // The scoped export captured the tenant's own user row (>=1).
        $this->assertStringContainsString('"users"', (string)$row['row_counts']);
        $this->assertGreaterThanOrEqual(1, (int)$conn->execute(
            "SELECT count(*) c FROM audit_log WHERE action = 'tenant.backup.create' AND tenant_id = :t",
            ['t' => $this->tenantId],
        )->fetch('assoc')['c'], 'create is audited');
    }

    public function testDownloadReturnsArchive(): void
    {
        $this->login($this->userId);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $conn = ConnectionManager::get('default');

        $this->post('/admin/tenant-backup/create', []);
        $id = (string)$conn->execute(
            'SELECT id FROM tenant_backups WHERE tenant_id = :t',
            ['t' => $this->tenantId],
        )->fetch('assoc')['id'];

        $this->post('/admin/tenant-backup/download/' . $id);
        $this->assertResponseOk();
        $this->assertHeaderContains('Content-Disposition', 'attachment');
    }

    public function testCrossTenantIsolation(): void
    {
        // Create a backup for tenant A (setUp tenant).
        $this->login($this->userId);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/admin/tenant-backup/create', []);
        $conn = ConnectionManager::get('default');
        $aFile = (string)$conn->execute(
            'SELECT filename FROM tenant_backups WHERE tenant_id = :t',
            ['t' => $this->tenantId],
        )->fetch('assoc')['filename'];

        // A second tenant B with its own admin must not see A's backup in the list.
        $tidB = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zztbk-' || substr(md5(random()::text), 1, 8), 'ZZ B') RETURNING id",
        )->fetch('assoc')['id'];
        $uidB = (string)$conn->execute(
            "INSERT INTO users (username, email, status, tenant_id) VALUES (:u, :e, 'active', :t) RETURNING id",
            ['u' => 'zztbk_b_' . bin2hex(random_bytes(3)), 'e' => 'tbkb_' . bin2hex(random_bytes(3)) . '@zztbk.local', 't' => $tidB],
        )->fetch('assoc')['id'];
        $conn->execute('INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)', ['u' => $uidB, 'a' => 'tenant_backup']);

        $this->login($uidB);
        $this->get('/admin/tenant-backup');
        $this->assertResponseOk();
        $this->assertResponseNotContains($aFile, "tenant B must not see tenant A's backup");
    }

    public function testRestoreRoundTripAndTenantScoped(): void
    {
        $this->login($this->userId);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $conn = ConnectionManager::get('default');

        // Tenant A has an automation rule "zzrest_orig"; a separate tenant B has its
        // own rule "zzrest_b" that the restore must never touch.
        $this->makeRule('zzrest_orig', $this->tenantId);
        $tidB = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zztbk-' || substr(md5(random()::text), 1, 8), 'ZZ B') RETURNING id",
        )->fetch('assoc')['id'];
        $this->makeRule('zzrest_b', $tidB);

        // Back up tenant A (captures zzrest_orig), then DIVERGE: drop the original
        // rule and add a new one that is NOT in the backup.
        $this->post('/admin/tenant-backup/create', []);
        $id = (string)$conn->execute(
            'SELECT id FROM tenant_backups WHERE tenant_id = :t',
            ['t' => $this->tenantId],
        )->fetch('assoc')['id'];
        $conn->execute("DELETE FROM automation_rules WHERE name = 'zzrest_orig'");
        $this->makeRule('zzrest_new', $this->tenantId);

        // Restore -> tenant A is reset to the backup state.
        $this->post('/admin/tenant-backup/restore/' . $id);
        $this->assertRedirect(['action' => 'index']);

        $names = array_map(
            static fn(array $r): string => (string)$r['name'],
            $conn->execute(
                "SELECT name FROM automation_rules WHERE tenant_id = :t AND name LIKE 'zzrest%'",
                ['t' => $this->tenantId],
            )->fetchAll('assoc'),
        );
        $this->assertContains('zzrest_orig', $names, 'the backed-up rule is restored');
        $this->assertNotContains('zzrest_new', $names, 'a change made after the backup is undone');
        // Tenant B is untouched.
        $this->assertSame(1, (int)$conn->execute(
            "SELECT count(*) c FROM automation_rules WHERE tenant_id = :t AND name = 'zzrest_b'",
            ['t' => $tidB],
        )->fetch('assoc')['c'], "another tenant's data is never affected");
        // The restore is audited.
        $this->assertGreaterThanOrEqual(1, (int)$conn->execute(
            "SELECT count(*) c FROM audit_log WHERE action = 'tenant.restore' AND tenant_id = :t",
            ['t' => $this->tenantId],
        )->fetch('assoc')['c'], 'restore is audited');

        $conn->execute("DELETE FROM automation_rules WHERE name = 'zzrest_b'");
    }

    public function testRestoreRollsBackOnFailureNoDataLoss(): void
    {
        // Atomicity (review HIGH): a restore that fails mid-way must roll back its
        // DELETEs too, so the tenant keeps its pre-restore data. Failure is induced
        // via a notification whose user is deleted after the backup -> the reinsert
        // hits the notifications.user_id FK.
        $this->login($this->userId);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $conn = ConnectionManager::get('default');

        $xid = (string)$conn->execute(
            "INSERT INTO users (username, email, status, tenant_id) VALUES (:u, :e, 'active', :t) RETURNING id",
            ['u' => 'zztbk_x_' . bin2hex(random_bytes(3)), 'e' => 'x_' . bin2hex(random_bytes(3)) . '@zztbk.local', 't' => $this->tenantId],
        )->fetch('assoc')['id'];
        $conn->execute(
            "INSERT INTO notifications (user_id, type, title, body, data, tenant_id) "
            . "VALUES (:u, 'test', 'T', '', '{}'::jsonb, :t)",
            ['u' => $xid, 't' => $this->tenantId],
        );

        $this->post('/admin/tenant-backup/create', []);
        $id = (string)$conn->execute(
            'SELECT id FROM tenant_backups WHERE tenant_id = :t',
            ['t' => $this->tenantId],
        )->fetch('assoc')['id'];

        // Drop the user (cascades the live notification) -> the backup's notification
        // now references a missing user -> reinsert FK-fails.
        $conn->execute('DELETE FROM users WHERE id = :id', ['id' => $xid]);
        // A marker the restore's DELETE removes — and must restore on rollback.
        $this->makeRule('zzrest_marker', $this->tenantId);

        $this->post('/admin/tenant-backup/restore/' . $id);
        $this->assertRedirect(['action' => 'index']);

        $this->assertSame(1, (int)$conn->execute(
            "SELECT count(*) c FROM automation_rules WHERE tenant_id = :t AND name = 'zzrest_marker'",
            ['t' => $this->tenantId],
        )->fetch('assoc')['c'], 'a failed restore must not delete the tenant data (atomic rollback)');
    }

    private function makeRule(string $name, string $tenantId): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO automation_rules (name, event, condition, actions, active, tenant_id) '
            . "VALUES (:n, 'zztest.evt', '{}'::jsonb, '[]'::jsonb, true, :t)",
            ['n' => $name, 't' => $tenantId],
        );
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array)scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir . '/' . $f;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
