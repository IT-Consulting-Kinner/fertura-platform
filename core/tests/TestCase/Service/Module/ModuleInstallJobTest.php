<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Module;

use App\Service\Module\ModuleInstallJobService;
use App\Service\Module\ModuleInstallRunner;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Async GUI module-install queue (core.module_install_jobs): enqueue -> claim ->
 * process -> status. The worker calls {@see ModuleInstallRunner::tick()} once per
 * cycle; here we drive it directly with a bogus package to prove the failure path
 * (extract guard) updates the job, without needing the full install machinery.
 */
class ModuleInstallJobTest extends TestCase
{
    private ModuleInstallJobService $jobs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobs = new ModuleInstallJobService();
        ConnectionManager::get('default')->execute('DELETE FROM core.module_install_jobs');
    }

    protected function tearDown(): void
    {
        ConnectionManager::get('default')->execute('DELETE FROM core.module_install_jobs');
        parent::tearDown();
    }

    public function testEnqueueThenGetAndActive(): void
    {
        $id = $this->jobs->enqueue('/tmp/pkg.zip', 'pkg.zip', null);
        $this->assertNotSame('', $id);

        $job = $this->jobs->get($id);
        $this->assertNotNull($job);
        $this->assertSame('queued', $job['status']);
        $this->assertSame('pkg.zip', $job['original_filename']);

        $active = $this->jobs->active();
        $this->assertNotNull($active);
        $this->assertSame($id, $active['id']);
    }

    public function testClaimNextTransitionsToRunningAndIsExclusive(): void
    {
        $id = $this->jobs->enqueue('/tmp/pkg.zip', 'pkg.zip', null);

        $claimed = $this->jobs->claimNext();
        $this->assertNotNull($claimed);
        $this->assertSame($id, $claimed['id']);
        $this->assertSame('running', $claimed['status']);
        $this->assertNotNull($claimed['started_at']);

        // No further queued job -> a second claim returns null.
        $this->assertNull($this->jobs->claimNext());
    }

    public function testMarkSucceededAndFailed(): void
    {
        $a = $this->jobs->enqueue('/tmp/a.zip', 'a.zip', null);
        $this->jobs->markSucceeded($a, 'demo_module');
        $job = $this->jobs->get($a);
        $this->assertSame('succeeded', $job['status']);
        $this->assertSame('demo_module', $job['module_key']);
        $this->assertNotNull($job['finished_at']);

        $b = $this->jobs->enqueue('/tmp/b.zip', 'b.zip', null);
        $this->jobs->markFailed($b, 'boom');
        $this->assertSame('failed', $this->jobs->get($b)['status']);
        $this->assertSame('boom', $this->jobs->get($b)['message']);
    }

    public function testRunnerMarksMissingPackageFailed(): void
    {
        $id = $this->jobs->enqueue('/tmp/does-not-exist-' . bin2hex(random_bytes(4)) . '.zip', 'x.zip', null);

        $processed = (new ModuleInstallRunner())->tick();
        $this->assertSame($id, $processed);

        $job = $this->jobs->get($id);
        $this->assertSame('failed', $job['status']);
        $this->assertNotNull($job['message']);
        $this->assertNotNull($job['finished_at']);
    }

    public function testRunnerTickReturnsNullWhenQueueEmpty(): void
    {
        $this->assertNull((new ModuleInstallRunner())->tick());
    }
}
