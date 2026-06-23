<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Backup;

use App\Service\Backup\BackupService;
use Cake\TestSuite\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Peer-review F18: the tar-safety check run before a destructive restore must
 * reject not only path traversal in entry NAMES but also non-regular entries
 * (symlink/hardlink/device) — a benign-named symlink could otherwise redirect a
 * later regular-file write outside ROOT even though every name looks safe.
 */
class BackupTarSafetyTest extends TestCase
{
    private function callAssertSafeTar(string $tarGz): void
    {
        $svc = (new ReflectionClass(BackupService::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod(BackupService::class, 'assertSafeTar');
        $m->setAccessible(true);
        $m->invoke($svc, $tarGz);
    }

    private function tarball(string $dir): string
    {
        $tar = sys_get_temp_dir() . '/zztarsafe_' . bin2hex(random_bytes(4)) . '.tar.gz';
        exec('tar czf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg($dir) . ' . 2>/dev/null', $o, $rc);
        $this->assertSame(0, $rc, 'tar must build the fixture archive');

        return $tar;
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
            $p = $dir . '/' . $f;
            is_dir($p) && !is_link($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    public function testAcceptsRegularFilesAndDirectories(): void
    {
        $dir = sys_get_temp_dir() . '/zztarsafe_src_' . bin2hex(random_bytes(4));
        @mkdir($dir . '/sub', 0o775, true);
        file_put_contents($dir . '/sub/ok.txt', 'safe');
        $tar = $this->tarball($dir);
        try {
            $this->callAssertSafeTar($tar); // no exception => accepted
            $this->addToAssertionCount(1);
        } finally {
            @unlink($tar);
            $this->rrmdir($dir);
        }
    }

    public function testRejectsSymlinkEntry(): void
    {
        $dir = sys_get_temp_dir() . '/zztarsafe_src_' . bin2hex(random_bytes(4));
        @mkdir($dir, 0o775, true);
        file_put_contents($dir . '/real.txt', 'x');
        if (!@symlink('/etc', $dir . '/evil')) {
            $this->rrmdir($dir);
            $this->markTestSkipped('symlink creation not supported here');
        }
        $tar = $this->tarball($dir);
        try {
            $this->expectException(RuntimeException::class);
            $this->callAssertSafeTar($tar);
        } finally {
            @unlink($tar);
            $this->rrmdir($dir);
        }
    }
}
