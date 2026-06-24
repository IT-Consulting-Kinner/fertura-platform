<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Storage;

use App\Service\Storage\StorageManager;
use App\Service\Storage\TenantStorage;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Throwable;

/**
 * Tests the per-tenant file-scoping helper (tenant-backup design §4, Increment 6c).
 *
 * {@see TenantStorage} is a deliberately published extension interface for (external)
 * modules that adopt the `tenant/<id>/…` file convention — its instance API has no
 * in-repo caller, so this is its only coverage. It resolves paths against the CURRENT
 * tenant from the RLS context (core.current_tenant()), which the app sets per request
 * via `SET LOCAL app.current_tenant_id`; the tests set it the same way and run each
 * operation inside a rolled-back transaction so the context never leaks. Files are
 * written through a real {@see StorageManager} (local adapter) and the tenant subtrees
 * are removed in cleanup — mirroring TenantBackupControllerTest.
 */
class TenantStorageTest extends TestCase
{
    private string $tenantA = '';
    private string $tenantB = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->tenantA = $this->makeTenant();
        $this->tenantB = $this->makeTenant();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = $this->conn();
        // Remove the file subtrees of the test tenants (found via their still-present
        // rows), then the rows themselves.
        $storage = new StorageManager();
        foreach ($conn->execute("SELECT id FROM tenants WHERE key LIKE 'zzts-%'")->fetchAll('assoc') as $t) {
            try {
                $storage->deleteDirectory('tenant/' . (string)$t['id']);
            } catch (Throwable) {
                // No file subtree for this tenant.
            }
        }
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zzts-%'");
    }

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $c */
        $c = ConnectionManager::get('default');

        return $c;
    }

    private function makeTenant(): string
    {
        return (string)$this->conn()->execute(
            "INSERT INTO tenants (key, name) VALUES ('zzts-' || substr(md5(random()::text), 1, 8), 'ZZ Storage') RETURNING id",
        )->fetch('assoc')['id'];
    }

    /**
     * Runs $fn within the request-style RLS context for $tenantId (SET LOCAL
     * app.current_tenant_id — the value core.current_tenant() returns). The wrapping
     * transaction is rolled back afterwards so the context never leaks; only DB reads
     * happen inside, while file writes hit the (non-transactional) storage and persist.
     *
     * @param callable():mixed $fn
     */
    private function withTenant(string $tenantId, callable $fn): mixed
    {
        $conn = $this->conn();
        $conn->begin();
        try {
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $tenantId]);

            return $fn();
        } finally {
            $conn->rollback();
        }
    }

    public function testPrefixReturnsTenantSlashIdSlash(): void
    {
        $this->assertSame('tenant/abc-123/', TenantStorage::prefix('abc-123'));
    }

    public function testTenantPathPrefixesWithCurrentTenant(): void
    {
        $path = $this->withTenant($this->tenantA, fn(): string => (new TenantStorage())->tenantPath('uploads/x.pdf'));

        $this->assertSame('tenant/' . $this->tenantA . '/uploads/x.pdf', $path);
    }

    public function testTenantPathStripsLeadingSlashOnRelative(): void
    {
        $path = $this->withTenant($this->tenantA, fn(): string => (new TenantStorage())->tenantPath('/uploads/x.pdf'));

        $this->assertSame('tenant/' . $this->tenantA . '/uploads/x.pdf', $path);
    }

    public function testWriteReadRoundTripUnderTenantPrefix(): void
    {
        $read = $this->withTenant($this->tenantA, static function (): string {
            $ts = new TenantStorage();
            $ts->write('docs/hello.txt', 'HELLO');

            return $ts->read('docs/hello.txt');
        });
        $this->assertSame('HELLO', $read);

        // The bytes really landed under the current tenant's prefix in the store.
        $this->assertSame(
            'HELLO',
            (new StorageManager())->read('tenant/' . $this->tenantA . '/docs/hello.txt'),
            'the file is stored under the current tenant prefix',
        );
    }

    public function testListIsScopedToCurrentTenant(): void
    {
        // Each tenant writes one file under its own (context-derived) prefix.
        $this->withTenant($this->tenantA, static function (): void {
            (new TenantStorage())->write('a.txt', 'A');
        });
        $this->withTenant($this->tenantB, static function (): void {
            (new TenantStorage())->write('b.txt', 'B');
        });

        $listA = $this->withTenant($this->tenantA, fn(): array => (new TenantStorage())->list());
        $listB = $this->withTenant($this->tenantB, fn(): array => (new TenantStorage())->list());

        // Each context sees only its own subtree — tenant isolation by prefix.
        $this->assertContains('tenant/' . $this->tenantA . '/a.txt', $listA);
        $this->assertNotContains(
            'tenant/' . $this->tenantB . '/b.txt',
            $listA,
            "tenant A must not see tenant B's files",
        );
        $this->assertContains('tenant/' . $this->tenantB . '/b.txt', $listB);
        $this->assertNotContains(
            'tenant/' . $this->tenantA . '/a.txt',
            $listB,
            "tenant B must not see tenant A's files",
        );
    }
}
