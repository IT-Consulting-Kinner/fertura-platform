<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Storage;

use App\Service\Storage\StorageException;
use App\Service\Storage\StorageManager;
use Cake\TestSuite\TestCase;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Tests the object-storage abstraction (P03) against the local adapter (no
 * network): write/read/exists/size/streams/list/delete + error translation.
 */
class StorageManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/fertura_store_' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    private function manager(): StorageManager
    {
        return new StorageManager(new Filesystem(new LocalFilesystemAdapter($this->root)));
    }

    public function testWriteReadExistsSizeDelete(): void
    {
        $s = $this->manager();
        $s->write('dir/a.txt', 'hello');

        $this->assertTrue($s->exists('dir/a.txt'));
        $this->assertSame('hello', $s->read('dir/a.txt'));
        $this->assertSame(5, $s->fileSize('dir/a.txt'));

        $s->delete('dir/a.txt');
        $this->assertFalse($s->exists('dir/a.txt'));
    }

    public function testStreams(): void
    {
        $s = $this->manager();
        $tmp = fopen('php://temp', 'r+');
        fwrite($tmp, 'stream-me');
        rewind($tmp);
        $s->writeStream('s/x.bin', $tmp);
        fclose($tmp);

        $r = $s->readStream('s/x.bin');
        $this->assertSame('stream-me', stream_get_contents($r));
        fclose($r);
    }

    public function testListReturnsFiles(): void
    {
        $s = $this->manager();
        $s->write('d/a.txt', '1');
        $s->write('d/b.txt', '2');

        $list = $s->list('d');
        sort($list);
        $this->assertSame(['d/a.txt', 'd/b.txt'], $list);
    }

    public function testReadMissingThrows(): void
    {
        $this->expectException(StorageException::class);
        $this->manager()->read('does/not/exist.txt');
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
