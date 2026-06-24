<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use App\Service\Backup\TenantBackupPlanService;
use App\Service\Storage\StorageManager;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\UploadedFile;
use Throwable;
use ZipArchive;

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
        $storage = new StorageManager();
        foreach ($conn->execute("SELECT id FROM tenants WHERE key LIKE 'zztbk-%'")->fetchAll('assoc') as $t) {
            $this->rrmdir(ROOT . '/backups/tenant/' . (string)$t['id']);
            try {
                $storage->deleteDirectory('tenant/' . (string)$t['id']);
            } catch (Throwable) {
                // No file subtree for this tenant.
            }
        }
        $conn->execute(
            'DELETE FROM user_admin_areas WHERE user_id IN '
            . "(SELECT id FROM users WHERE email LIKE '%@zztbk.local')",
        );
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zztbk.local'");
        // Tenant-scoped child rows referencing the test tenants (no ON DELETE CASCADE).
        $conn->execute("DELETE FROM automation_rules WHERE name LIKE 'zzrest%'");
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zztbk-%'");
        // The fixture module schemas — drop so other tests do not discover them.
        $conn->execute('DROP SCHEMA IF EXISTS zzmod6d CASCADE');
        $conn->execute('DROP SCHEMA IF EXISTS mod_zztest7a CASCADE');
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
            'INSERT INTO notifications (user_id, type, title, body, data, tenant_id) '
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

    public function testRestoreReplacesTenantFiles(): void
    {
        // Increment 6c: a tenant's files (tenant/<id>/...) are backed up + restored.
        $this->login($this->userId);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $conn = ConnectionManager::get('default');
        $storage = new StorageManager();
        $prefix = 'tenant/' . $this->tenantId . '/';

        $storage->write($prefix . 'docs/orig.txt', 'ORIGINAL');
        $this->post('/admin/tenant-backup/create', []);
        $id = (string)$conn->execute(
            'SELECT id FROM tenant_backups WHERE tenant_id = :t',
            ['t' => $this->tenantId],
        )->fetch('assoc')['id'];

        // Diverge: change the backed-up file and add one that is NOT in the backup.
        $storage->write($prefix . 'docs/orig.txt', 'CHANGED');
        $storage->write($prefix . 'docs/extra.txt', 'EXTRA');

        $this->post('/admin/tenant-backup/restore/' . $id);
        $this->assertRedirect(['action' => 'index']);

        $this->assertSame('ORIGINAL', $storage->read($prefix . 'docs/orig.txt'), 'the backed-up file content is restored');
        $this->assertFalse($storage->exists($prefix . 'docs/extra.txt'), 'a file added after the backup is pruned');
    }

    public function testRestoreRejectsPathTraversalEntries(): void
    {
        // Zip-slip defence (review HIGH): a crafted archive with a "files/../escape"
        // entry must NOT write outside the tenant's prefix; safe entries still apply.
        $this->login($this->userId);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $conn = ConnectionManager::get('default');

        $dir = ROOT . '/backups/tenant/' . $this->tenantId;
        @mkdir($dir, 0775, true);
        $zipPath = $dir . '/crafted_' . bin2hex(random_bytes(3)) . '.zip';
        $za = new ZipArchive();
        $za->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $za->addFromString('manifest.json', (string)json_encode([
            'format' => 'fertura-tenant-backup/1',
            'tenant_id' => $this->tenantId,
        ]));
        $za->addFromString('files/../escape.txt', 'EVIL');
        $za->addFromString('files/ok.txt', 'GOOD');
        $za->close();

        $id = (string)$conn->execute(
            'INSERT INTO tenant_backups (tenant_id, filename, storage_path) VALUES (:t, :f, :p) RETURNING id',
            ['t' => $this->tenantId, 'f' => basename($zipPath), 'p' => $zipPath],
        )->fetch('assoc')['id'];

        $this->post('/admin/tenant-backup/restore/' . $id);
        $this->assertRedirect(['action' => 'index']);

        $storage = new StorageManager();
        $this->assertTrue($storage->exists('tenant/' . $this->tenantId . '/ok.txt'), 'a safe entry is written');
        $this->assertFalse($storage->exists('escape.txt'), 'a traversal entry must not escape to the storage root');
        $this->assertFalse($storage->exists('tenant/escape.txt'), 'a traversal entry must not escape the tenant prefix');
    }

    public function testRestoreRoundTripIncludesModuleTables(): void
    {
        // Increment 6d: a module's tenant-scoped table (RLS + tenant_id in its own
        // schema) is discovered by introspection and backed up + restored.
        $this->login($this->userId);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $conn = ConnectionManager::get('default');

        $conn->execute('CREATE SCHEMA IF NOT EXISTS zzmod6d');
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS zzmod6d.widgets '
            . '(id uuid PRIMARY KEY DEFAULT core.uuid_generate_v7(), tenant_id uuid NOT NULL, label text)',
        );
        $conn->execute('ALTER TABLE zzmod6d.widgets ENABLE ROW LEVEL SECURITY');
        $conn->execute('DROP POLICY IF EXISTS p_zzmod6d ON zzmod6d.widgets');
        $conn->execute('CREATE POLICY p_zzmod6d ON zzmod6d.widgets USING (true) WITH CHECK (true)');
        $conn->execute("INSERT INTO zzmod6d.widgets (tenant_id, label) VALUES (:t, 'ORIG')", ['t' => $this->tenantId]);

        $this->post('/admin/tenant-backup/create', []);
        $id = (string)$conn->execute(
            'SELECT id FROM tenant_backups WHERE tenant_id = :t',
            ['t' => $this->tenantId],
        )->fetch('assoc')['id'];

        // Diverge: change the module data after the backup.
        $conn->execute('DELETE FROM zzmod6d.widgets WHERE tenant_id = :t', ['t' => $this->tenantId]);
        $conn->execute("INSERT INTO zzmod6d.widgets (tenant_id, label) VALUES (:t, 'NEW')", ['t' => $this->tenantId]);

        $this->post('/admin/tenant-backup/restore/' . $id);
        $this->assertRedirect(['action' => 'index']);

        $labels = array_map(
            static fn(array $r): string => (string)$r['label'],
            $conn->execute('SELECT label FROM zzmod6d.widgets WHERE tenant_id = :t', ['t' => $this->tenantId])->fetchAll('assoc'),
        );
        $this->assertContains('ORIG', $labels, 'the module table is restored to the backup state');
        $this->assertNotContains('NEW', $labels, 'a post-backup module change is undone');
    }

    public function testUploadRestoreRoundTrip(): void
    {
        // Increment 6 (upload-restore): a tenant admin restores from an UPLOADED
        // backup file (one they downloaded earlier) rather than a server-stored one.
        $this->login($this->userId);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $conn = ConnectionManager::get('default');

        // Back up tenant A (captures zzrest_orig), copy the archive to act as the
        // upload, then DIVERGE the live data.
        $this->makeRule('zzrest_orig', $this->tenantId);
        $this->post('/admin/tenant-backup/create', []);
        $archive = (string)$conn->execute(
            'SELECT storage_path FROM tenant_backups WHERE tenant_id = :t',
            ['t' => $this->tenantId],
        )->fetch('assoc')['storage_path'];

        // The copy lives under the tenant's backup dir so cleanup removes it.
        $upload = ROOT . '/backups/tenant/' . $this->tenantId . '/upload_' . bin2hex(random_bytes(3)) . '.zip';
        copy($archive, $upload);
        $conn->execute("DELETE FROM automation_rules WHERE name = 'zzrest_orig'");
        $this->makeRule('zzrest_new', $this->tenantId);

        // Upload the copy -> tenant A is reset to the backup state.
        $this->configRequest(['files' => [
            'archive' => new UploadedFile($upload, (int)filesize($upload), UPLOAD_ERR_OK, 'backup.zip', 'application/zip'),
        ]]);
        $this->post('/admin/tenant-backup/upload', []);
        $this->assertRedirect(['action' => 'index']);

        $names = array_map(
            static fn(array $r): string => (string)$r['name'],
            $conn->execute(
                "SELECT name FROM automation_rules WHERE tenant_id = :t AND name LIKE 'zzrest%'",
                ['t' => $this->tenantId],
            )->fetchAll('assoc'),
        );
        $this->assertContains('zzrest_orig', $names, 'the uploaded backup is restored');
        $this->assertNotContains('zzrest_new', $names, 'a change made after the backup is undone');
        $this->assertGreaterThanOrEqual(1, (int)$conn->execute(
            "SELECT count(*) c FROM audit_log WHERE action = 'tenant.backup.upload' AND tenant_id = :t",
            ['t' => $this->tenantId],
        )->fetch('assoc')['c'], 'upload-restore is audited');
    }

    public function testUploadRejectsForeignTenantArchive(): void
    {
        // Security: uploading ANOTHER tenant's archive must be rejected (manifest
        // tenant mismatch) and must not modify the uploading tenant's data.
        $this->login($this->userId);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $conn = ConnectionManager::get('default');

        // Tenant A makes a backup; capture its archive path.
        $this->makeRule('zzrest_a', $this->tenantId);
        $this->post('/admin/tenant-backup/create', []);
        $archiveA = (string)$conn->execute(
            'SELECT storage_path FROM tenant_backups WHERE tenant_id = :t',
            ['t' => $this->tenantId],
        )->fetch('assoc')['storage_path'];

        // Tenant B (own admin) keeps its own rule and tries to upload A's archive.
        $tidB = (string)$conn->execute(
            "INSERT INTO tenants (key, name) VALUES ('zztbk-' || substr(md5(random()::text), 1, 8), 'ZZ B') RETURNING id",
        )->fetch('assoc')['id'];
        $uidB = (string)$conn->execute(
            "INSERT INTO users (username, email, status, tenant_id) VALUES (:u, :e, 'active', :t) RETURNING id",
            ['u' => 'zztbk_b_' . bin2hex(random_bytes(3)), 'e' => 'tbkb_' . bin2hex(random_bytes(3)) . '@zztbk.local', 't' => $tidB],
        )->fetch('assoc')['id'];
        $conn->execute('INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)', ['u' => $uidB, 'a' => 'tenant_backup']);
        $this->makeRule('zzrest_b_keep', $tidB);

        $dirB = ROOT . '/backups/tenant/' . $tidB;
        @mkdir($dirB, 0o775, true);
        $foreign = $dirB . '/foreign_' . bin2hex(random_bytes(3)) . '.zip';
        copy($archiveA, $foreign);

        $this->login($uidB);
        $this->configRequest(['files' => [
            'archive' => new UploadedFile($foreign, (int)filesize($foreign), UPLOAD_ERR_OK, 'foreign.zip', 'application/zip'),
        ]]);
        $this->post('/admin/tenant-backup/upload', []);
        $this->assertRedirect(['action' => 'index']);

        // B's data is untouched and the rejected upload performed no restore.
        $this->assertSame(1, (int)$conn->execute(
            "SELECT count(*) c FROM automation_rules WHERE tenant_id = :t AND name = 'zzrest_b_keep'",
            ['t' => $tidB],
        )->fetch('assoc')['c'], "a foreign archive must not modify the uploading tenant's data");
        $this->assertSame(0, (int)$conn->execute(
            "SELECT count(*) c FROM audit_log WHERE action = 'tenant.restore' AND tenant_id = :t",
            ['t' => $tidB],
        )->fetch('assoc')['c'], 'a rejected upload performs no restore');
    }

    public function testScopedBackupCoversOnlyItsScope(): void
    {
        // Inc 7a: a 'core' backup contains only the tenant's core tables; a module
        // scope contains only that module's table — never the other's.
        $this->login($this->userId);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $conn = ConnectionManager::get('default');

        // Module fixture in a mod_<key> schema -> valid module scope 'zztest7a'.
        $conn->execute('CREATE SCHEMA IF NOT EXISTS mod_zztest7a');
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS mod_zztest7a.widgets '
            . '(id uuid PRIMARY KEY DEFAULT core.uuid_generate_v7(), tenant_id uuid NOT NULL, label text)',
        );
        $conn->execute('ALTER TABLE mod_zztest7a.widgets ENABLE ROW LEVEL SECURITY');
        $conn->execute('DROP POLICY IF EXISTS p_zztest7a ON mod_zztest7a.widgets');
        $conn->execute('CREATE POLICY p_zztest7a ON mod_zztest7a.widgets USING (true) WITH CHECK (true)');
        $conn->execute("INSERT INTO mod_zztest7a.widgets (tenant_id, label) VALUES (:t, 'W')", ['t' => $this->tenantId]);
        $this->makeRule('zzrest_scope', $this->tenantId); // core data

        $latest = fn(): array => (array)$conn->execute(
            'SELECT scope, row_counts FROM tenant_backups WHERE tenant_id = :t ORDER BY created_at DESC LIMIT 1',
            ['t' => $this->tenantId],
        )->fetch('assoc');

        // 'core' scope -> core tables only, no module table.
        $this->post('/admin/tenant-backup/create', ['scope' => 'core']);
        $this->assertRedirect(['action' => 'index']);
        $core = $latest();
        $this->assertSame('core', $core['scope']);
        $this->assertStringContainsString('automation_rules', (string)$core['row_counts']);
        $this->assertStringNotContainsString('mod_zztest7a', (string)$core['row_counts'], 'core scope excludes module tables');

        // module scope -> only that module's table, no core table.
        $this->post('/admin/tenant-backup/create', ['scope' => 'zztest7a']);
        $mod = $latest();
        $this->assertSame('zztest7a', $mod['scope']);
        $this->assertStringContainsString('mod_zztest7a.widgets', (string)$mod['row_counts']);
        $this->assertStringNotContainsString('automation_rules', (string)$mod['row_counts'], 'module scope excludes core tables');

        // An unknown scope is rejected (would otherwise back up nothing).
        $this->post('/admin/tenant-backup/create', ['scope' => 'does_not_exist']);
        $this->assertFlashElement('flash/error');
    }

    public function testPlanCreateToggleDelete(): void
    {
        // Inc 7b: create a scheduled plan, toggle it inactive, delete it — all under
        // the tenant's RLS context (DashedRoute -> create-plan/toggle-plan/delete-plan).
        $this->login($this->userId);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $conn = ConnectionManager::get('default');

        $this->post('/admin/tenant-backup/create-plan', [
            'name' => 'zzplan_daily', 'scope' => 'core', 'cadence' => 'daily', 'hour' => 3,
            'weekday' => 1, 'day_of_month' => 1, 'retention_keep' => 5, 'active' => '1',
        ]);
        $this->assertRedirect(['action' => 'index']);
        $plan = $conn->execute(
            'SELECT id, scope, cadence, retention_keep, next_run_at FROM tenant_backup_plans '
            . "WHERE tenant_id = :t AND name = 'zzplan_daily'",
            ['t' => $this->tenantId],
        )->fetch('assoc');
        $this->assertNotFalse($plan, 'the plan row is created');
        $this->assertSame('core', $plan['scope']);
        $this->assertSame('daily', $plan['cadence']);
        $this->assertNotNull($plan['next_run_at'], 'next_run_at is computed on create');

        // Toggle inactive.
        $this->post('/admin/tenant-backup/toggle-plan/' . $plan['id'], ['active' => '0']);
        $this->assertSame(0, (int)$conn->execute(
            'SELECT active::int a FROM tenant_backup_plans WHERE id = :id',
            ['id' => $plan['id']],
        )->fetch('assoc')['a'], 'the plan is toggled inactive');

        // Delete.
        $this->post('/admin/tenant-backup/delete-plan/' . $plan['id']);
        $this->assertSame(0, (int)$conn->execute(
            "SELECT count(*) c FROM tenant_backup_plans WHERE tenant_id = :t AND name = 'zzplan_daily'",
            ['t' => $this->tenantId],
        )->fetch('assoc')['c'], 'the plan is deleted');
    }

    public function testSchedulerRunsDuePlanWithRetention(): void
    {
        // Inc 7c: a due plan creates a scoped, plan-linked backup, advances next_run,
        // and prunes to its retention. runDuePlans is RLS-scoped, so set the context.
        $conn = ConnectionManager::get('default');
        $conn->execute("SELECT set_config('app.current_tenant_id', :t, false)", ['t' => $this->tenantId]);
        try {
            $planId = (string)$conn->execute(
                'INSERT INTO tenant_backup_plans '
                . '(tenant_id, name, scope, cadence, hour, retention_keep, active, next_run_at) '
                . "VALUES (:t, 'zzplan_due', 'core', 'daily', 2, 1, true, now() - interval '1 hour') RETURNING id",
                ['t' => $this->tenantId],
            )->fetch('assoc')['id'];

            $svc = new TenantBackupPlanService();
            $this->assertSame(1, $svc->runDuePlans(), 'the due plan ran');

            $bk = $conn->execute(
                'SELECT scope FROM tenant_backups WHERE tenant_id = :t AND plan_id = :p',
                ['t' => $this->tenantId, 'p' => $planId],
            )->fetch('assoc');
            $this->assertNotFalse($bk, 'a plan-linked backup was created');
            $this->assertSame('core', $bk['scope']);

            $adv = $conn->execute(
                'SELECT (next_run_at > now()) AS f, (last_run_at IS NOT NULL) AS l '
                . 'FROM tenant_backup_plans WHERE id = :id',
                ['id' => $planId],
            )->fetch('assoc');
            $this->assertTrue($adv['f'] === true || $adv['f'] === 't', 'next_run advanced to the future');
            $this->assertTrue($adv['l'] === true || $adv['l'] === 't', 'last_run recorded');

            // Force due again -> a second backup is created, then pruned to keep=1.
            $conn->execute("UPDATE tenant_backup_plans SET next_run_at = now() - interval '1 hour' WHERE id = :id", ['id' => $planId]);
            $this->assertSame(1, $svc->runDuePlans());
            $this->assertSame(1, (int)$conn->execute(
                'SELECT count(*) c FROM tenant_backups WHERE tenant_id = :t AND plan_id = :p',
                ['t' => $this->tenantId, 'p' => $planId],
            )->fetch('assoc')['c'], 'retention_keep=1 keeps only the newest plan backup');
        } finally {
            $conn->execute("SELECT set_config('app.current_tenant_id', '', false)");
        }
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
