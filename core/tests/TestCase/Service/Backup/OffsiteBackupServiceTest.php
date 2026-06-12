<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Backup;

use App\Service\Backup\OffsiteBackupService;
use App\Service\Storage\StorageManager;
use Cake\TestSuite\TestCase;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

/**
 * Test of off-site backup storage (P14) against a local storage adapter
 * (standing in for S3): upload, listing, download.
 */
class OffsiteBackupServiceTest extends TestCase
{
    private string $root;
    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/fertura_offsite_' . bin2hex(random_bytes(5));
        $this->work = sys_get_temp_dir() . '/fertura_offsite_work_' . bin2hex(random_bytes(5));
        @mkdir($this->work, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        $this->rrmdir($this->work);
        parent::tearDown();
    }

    private function service(): OffsiteBackupService
    {
        return new OffsiteBackupService(new StorageManager(new Filesystem(new LocalFilesystemAdapter($this->root))));
    }

    public function testUploadListDownloadRoundtrip(): void
    {
        $local = $this->work . '/backup_20260101.zip';
        file_put_contents($local, 'ZIP-CONTENT-XYZ');

        $svc = $this->service();
        $target = $svc->upload($local);
        $this->assertSame('backups/backup_20260101.zip', $target);

        $this->assertSame(['backups/backup_20260101.zip'], $svc->list());

        $restored = $this->work . '/restored.zip';
        $svc->download('backup_20260101.zip', $restored);
        $this->assertSame('ZIP-CONTENT-XYZ', file_get_contents($restored));

        $svc->delete('backup_20260101.zip');
        $this->assertSame([], $svc->list());
    }

    public function testUploadMissingFileThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->upload($this->work . '/nope.zip');
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $full = $path . '/' . $i;
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
