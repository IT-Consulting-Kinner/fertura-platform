<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Storage;

use App\Service\Storage\StorageManager;
use App\Service\Storage\StorageRehomer;
use Cake\TestSuite\TestCase;
use FilesystemIterator;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Re-homes a module's legacy files into the per-tenant convention (Inc 8): moves the
 * bytes, verifies before delete, is idempotent, and reports gaps/conflicts — all
 * against a temp-rooted local Flysystem (no network, no DB).
 */
class StorageRehomerTest extends TestCase
{
    private string $root = '';
    private StorageManager $sm;
    private StorageRehomer $rehomer;

    private const TENANT = '11111111-1111-1111-1111-111111111111';
    private const TARGET = 'tenant/11111111-1111-1111-1111-111111111111/ticketing/attachments/x.pdf';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zzrehome_' . bin2hex(random_bytes(6));
        $this->sm = new StorageManager(new Filesystem(new LocalFilesystemAdapter($this->root)));
        $this->rehomer = new StorageRehomer($this->sm);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    /** @return list<array<string,mixed>> */
    private function plan(string $source = 'legacy/t/x.pdf', string $target = 'attachments/x.pdf'): array
    {
        return [['tenant_id' => self::TENANT, 'source' => $source, 'target' => $target]];
    }

    public function testMovesLegacyFileIntoConventionAndDeletesSource(): void
    {
        $this->sm->write('legacy/t/x.pdf', 'DATA');

        $res = $this->rehomer->rehome('ticketing', $this->plan(), ['deleteSource' => true]);

        $this->assertSame('moved', $res[0]['status']);
        $this->assertSame(self::TARGET, $res[0]['target']);
        $this->assertSame('DATA', $this->sm->read(self::TARGET), 'Bytes müssen am Zielpfad ankommen.');
        $this->assertFalse($this->sm->exists('legacy/t/x.pdf'), 'Quelle muss nach verifiziertem Move entfernt sein.');
    }

    public function testReRunIsIdempotent(): void
    {
        $this->sm->write('legacy/t/x.pdf', 'DATA');
        $this->rehomer->rehome('ticketing', $this->plan(), ['deleteSource' => true]);

        // Source gone, target present -> a second run is a no-op 'already'.
        $res = $this->rehomer->rehome('ticketing', $this->plan(), ['deleteSource' => true]);
        $this->assertSame('already', $res[0]['status']);
        $this->assertSame('DATA', $this->sm->read(self::TARGET));
    }

    public function testDryRunWritesNothing(): void
    {
        $this->sm->write('legacy/t/x.pdf', 'DATA');

        $res = $this->rehomer->rehome('ticketing', $this->plan(), ['dryRun' => true, 'deleteSource' => true]);

        $this->assertSame('would_move', $res[0]['status']);
        $this->assertFalse($this->sm->exists(self::TARGET), 'Dry-Run darf kein Ziel schreiben.');
        $this->assertTrue($this->sm->exists('legacy/t/x.pdf'), 'Dry-Run darf die Quelle nicht löschen.');
    }

    public function testMissingSourceIsReported(): void
    {
        $res = $this->rehomer->rehome('ticketing', $this->plan(), ['deleteSource' => true]);

        $this->assertSame('missing_source', $res[0]['status']);
        $this->assertTrue(StorageRehomer::isError('missing_source'));
        $this->assertNotSame('', $res[0]['error']);
    }

    public function testConflictWhenTargetDiffersAndOverwriteForces(): void
    {
        $this->sm->write('legacy/t/x.pdf', 'DATA'); // 4 bytes
        $this->sm->write(self::TARGET, 'DIFFERENT-CONTENT'); // 17 bytes, blocks the move

        $conflict = $this->rehomer->rehome('ticketing', $this->plan(), []);
        $this->assertSame('conflict', $conflict[0]['status']);
        $this->assertSame('DIFFERENT-CONTENT', $this->sm->read(self::TARGET), 'Ohne --overwrite bleibt das Ziel unberührt.');

        $forced = $this->rehomer->rehome('ticketing', $this->plan(), ['overwrite' => true, 'deleteSource' => true]);
        $this->assertSame('moved', $forced[0]['status']);
        $this->assertSame('DATA', $this->sm->read(self::TARGET));
    }

    public function testInvalidTenantIdAndTraversalRejected(): void
    {
        $bad = $this->rehomer->rehome('ticketing', [
            ['tenant_id' => 'not/a/uuid', 'source' => 'legacy/t/x.pdf', 'target' => 'attachments/x.pdf'],
            ['tenant_id' => self::TENANT, 'source' => 'legacy/t/x.pdf', 'target' => '../escape.pdf'],
        ], []);

        $this->assertSame('error', $bad[0]['status']);
        $this->assertSame('error', $bad[1]['status']);
    }
}
