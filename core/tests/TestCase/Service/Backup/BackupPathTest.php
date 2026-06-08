<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Backup;

use App\Service\Backup\BackupPath;
use Cake\TestSuite\TestCase;
use RuntimeException;

class BackupPathTest extends TestCase
{
    public function testNormalizeConvertsSeparatorsAndCollapses(): void
    {
        $this->assertSame('C:/Backups/x', BackupPath::normalize('C:\\Backups\\x'));
        $this->assertSame('/mnt/backups', BackupPath::normalize('/mnt//backups/'));
        $this->assertSame('//server/share', BackupPath::normalize('\\\\server\\share\\'));
    }

    public function testStyleDetection(): void
    {
        $this->assertTrue(BackupPath::isWindows('C:/x'));
        $this->assertTrue(BackupPath::isWindows('//server/share'));
        $this->assertFalse(BackupPath::isWindows('/var/x'));
        $this->assertTrue(BackupPath::isLinux('/var/x'));
        $this->assertFalse(BackupPath::isLinux('//server/share'));
        $this->assertTrue(BackupPath::isAbsolute('/x'));
        $this->assertTrue(BackupPath::isAbsolute('C:/x'));
        $this->assertFalse(BackupPath::isAbsolute('relativ/pfad'));
    }

    public function testAssertUsableRejectsRelative(): void
    {
        $this->expectException(RuntimeException::class);
        BackupPath::assertUsable('relativ/pfad');
    }

    public function testAssertUsableAcceptsLinuxAbsoluteOnLinux(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Linux-spezifisch');
        }
        $this->assertSame('/var/backups', BackupPath::assertUsable('/var/backups'));
    }

    public function testAssertUsableRejectsWindowsPathOnLinux(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('nur auf Linux relevant');
        }
        $this->expectException(RuntimeException::class);
        BackupPath::assertUsable('C:/Backups');
    }
}
