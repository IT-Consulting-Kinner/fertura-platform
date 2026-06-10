<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Sdk;

use App\Service\Sdk\ManifestLinter;
use App\Service\Sdk\ModuleScaffolder;
use Cake\TestSuite\TestCase;

/**
 * Test des Modul-Scaffolders (P16): das Gerüst besteht den Linter sauber.
 */
class ModuleScaffolderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/fertura_scaffold_' . bin2hex(random_bytes(5));
        @mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
        parent::tearDown();
    }

    public function testScaffoldProducesLintCleanModule(): void
    {
        $files = (new ModuleScaffolder())->scaffold('mein_modul', 'Acme\\MeinModul', $this->dir);

        $this->assertContains('manifest.json', $files);
        $this->assertContains('src/PingEndpoint.php', $files);
        $this->assertFileExists($this->dir . '/mein_modul/manifest.json');
        $this->assertFileExists($this->dir . '/mein_modul/src/PingEndpoint.php');
        $this->assertFileExists($this->dir . '/mein_modul/migrations/001_init.sql');

        $lint = (new ManifestLinter())->lintFile($this->dir . '/mein_modul/manifest.json');
        $this->assertSame([], $lint['errors'], 'Gerüst-Manifest muss den Linter bestehen.');
    }

    public function testScaffoldRejectsBadKey(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ModuleScaffolder())->scaffold('Bad-Key', 'Acme\\X', $this->dir);
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
