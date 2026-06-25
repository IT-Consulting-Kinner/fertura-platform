<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Storage;

use App\Service\Module\ContributionRuntime;
use App\Service\Storage\ModuleStorage;
use App\Service\Storage\ModuleStorageScope;
use App\Service\Storage\StorageException;
use App\Service\Storage\StorageManager;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Throwable;

/**
 * A fixture "module" whose methods are invoked through {@see ContributionRuntime::call}
 * so the dispatch scope is set exactly as in production (Inc 8b).
 */
class ScopeProbeModule
{
    public function writeRaw(): void
    {
        (new StorageManager())->write('ticketing/raw.txt', 'X');
    }

    public function writeScoped(): string
    {
        return ModuleStorage::for('ticketing')->write('ok.txt', 'X');
    }

    public function writeReports(): void
    {
        (new StorageManager())->write('reports/probe.csv', 'X');
    }

    public function writeForeignModule(): void
    {
        // While dispatching as 'ticketing', try to write under another module's subtree.
        (new StorageManager())->write('tenant/11111111-1111-1111-1111-1111111111aa/knowledgebase/x.txt', 'X');
    }
}

/**
 * Integration test for the dispatch storage guard (Inc 8b): while a module contribution
 * runs, StorageManager refuses any write outside the module's tenant/<id>/<key>/ subtree
 * (allow-listing Core's own reports/), and the scope is restored afterwards. Core code
 * (module_key 'core') is never restricted.
 */
class ModuleStorageGuardTest extends TestCase
{
    private string $tenantId = '11111111-1111-1111-1111-1111111111aa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setTenant($this->tenantId);
    }

    protected function tearDown(): void
    {
        // Clear any scope a test left set, then clean stray files (no scope = unguarded).
        ModuleStorageScope::leave(null);
        $storage = new StorageManager();
        foreach (['tenant/' . $this->tenantId, 'ticketing/raw.txt', 'reports/probe.csv'] as $p) {
            try {
                str_contains($p, '.') ? $storage->delete($p) : $storage->deleteDirectory($p);
            } catch (Throwable) {
                // not written by this test
            }
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

    /** @param array<string,mixed> $extra */
    private function dispatch(string $method, string $moduleKey = 'ticketing'): mixed
    {
        return (new ContributionRuntime())->call(
            ['class' => ScopeProbeModule::class, 'module_key' => $moduleKey, 'isolation' => 'in_process'],
            $method,
            [],
        );
    }

    public function testRawWriteDuringDispatchIsRefused(): void
    {
        try {
            $this->dispatch('writeRaw');
            $this->fail('a raw storage-root write during a module dispatch must be refused');
        } catch (StorageException $e) {
            $this->assertStringContainsString('ticketing', $e->getMessage());
        }
        $this->assertFalse((new StorageManager())->exists('ticketing/raw.txt'), 'the file was not written');
    }

    public function testScopedWriteDuringDispatchIsAllowed(): void
    {
        $path = $this->dispatch('writeScoped');
        $this->assertSame('tenant/' . $this->tenantId . '/ticketing/ok.txt', $path);
        $this->assertTrue((new StorageManager())->exists($path));
    }

    public function testForeignModuleSubtreeIsRefused(): void
    {
        // Dispatching as 'ticketing', a write under another module's subtree is refused.
        $this->expectException(StorageException::class);
        $this->dispatch('writeForeignModule');
    }

    public function testAllowlistedReportsWriteIsAllowed(): void
    {
        $this->dispatch('writeReports');
        $this->assertTrue((new StorageManager())->exists('reports/probe.csv'), 'reports/ is allow-listed');
    }

    public function testScopeIsRestoredAfterDispatch(): void
    {
        try {
            $this->dispatch('writeScoped');
        } catch (Throwable) {
            // irrelevant here
        }
        $this->assertNull(ModuleStorageScope::active(), 'the scope is cleared after dispatch');
        // With no scope, a raw write is unguarded again.
        (new StorageManager())->write('ticketing/raw.txt', 'X');
        $this->assertTrue((new StorageManager())->exists('ticketing/raw.txt'));
    }

    public function testCoreContributionIsNotScoped(): void
    {
        // module_key 'core' = Core's own trusted code: the guard is not armed.
        $this->dispatch('writeRaw', 'core');
        $this->assertTrue((new StorageManager())->exists('ticketing/raw.txt'), 'core writes are unrestricted');
    }

    public function testNestingRestoresPreviousScope(): void
    {
        $this->assertNull(ModuleStorageScope::active());
        $prevA = ModuleStorageScope::enter('a');
        $this->assertNull($prevA);
        $prevB = ModuleStorageScope::enter('b');
        $this->assertSame('a', $prevB);
        $this->assertSame('b', ModuleStorageScope::active());
        ModuleStorageScope::leave($prevB);
        $this->assertSame('a', ModuleStorageScope::active(), 'inner leave restores the outer module');
        ModuleStorageScope::leave($prevA);
        $this->assertNull(ModuleStorageScope::active());
    }
}
