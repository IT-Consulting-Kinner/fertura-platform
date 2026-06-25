<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Storage;

use App\Service\Storage\ModuleStorage;
use App\Service\Storage\StorageException;
use App\Service\Storage\StorageManager;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Throwable;

/**
 * Unit test for the per-module storage handle (Inc 8a): paths are forced under
 * tenant/<id>/<key>/, the tenant is resolved live from the RLS context, and a write
 * with no tenant context fails closed.
 */
class ModuleStorageTest extends TestCase
{
    private string $tenantId = '11111111-1111-1111-1111-1111111111aa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setTenant($this->tenantId);
    }

    protected function tearDown(): void
    {
        try {
            (new StorageManager())->deleteDirectory('tenant/' . $this->tenantId);
        } catch (Throwable) {
            // nothing written
        }
        $this->setTenant('');
        parent::tearDown();
    }

    private function setTenant(string $tenantId): void
    {
        ConnectionManager::get('default')->execute(
            "SELECT set_config('app.current_tenant_id', :t, false)",
            ['t' => $tenantId],
        );
    }

    public function testPathIsForcedUnderTenantModulePrefix(): void
    {
        $s = ModuleStorage::for('ticketing');
        $this->assertSame(
            'tenant/' . $this->tenantId . '/ticketing/docs/x.txt',
            $s->path('docs/x.txt'),
        );
        // A leading slash on the relative path does not escape the prefix.
        $this->assertSame(
            'tenant/' . $this->tenantId . '/ticketing/y.txt',
            $s->path('/y.txt'),
        );
    }

    public function testWriteReturnsFullPathAndRoundTrips(): void
    {
        $s = ModuleStorage::for('knowledgebase');
        $path = $s->write('attachments/a/file.bin', 'HELLO');
        $this->assertSame('tenant/' . $this->tenantId . '/knowledgebase/attachments/a/file.bin', $path);
        $this->assertTrue($s->exists('attachments/a/file.bin'));
        $this->assertSame('HELLO', $s->read('attachments/a/file.bin'));
        // The returned path is the one the raw StorageManager resolves, too.
        $this->assertSame('HELLO', (new StorageManager())->read($path));
    }

    public function testListScopesToModuleSubtree(): void
    {
        $s = ModuleStorage::for('ticketing');
        $s->write('a.txt', 'A');
        $s->write('sub/b.txt', 'B');
        $listed = $s->list();
        $this->assertContains('tenant/' . $this->tenantId . '/ticketing/a.txt', $listed);
        $this->assertContains('tenant/' . $this->tenantId . '/ticketing/sub/b.txt', $listed);
    }

    public function testFailsClosedWithoutTenantContext(): void
    {
        $this->setTenant('');
        $s = ModuleStorage::for('ticketing');
        $this->expectException(StorageException::class);
        $s->write('x.txt', 'NOPE');
    }

    public function testInvalidModuleKeyThrows(): void
    {
        $this->expectException(StorageException::class);
        ModuleStorage::for('Bad-Key');
    }
}
