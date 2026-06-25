<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Sdk;

use App\Service\Sdk\ManifestLinter;
use Cake\TestSuite\TestCase;

/**
 * Test for the module source fence (Inc 8c): module code must not instantiate the
 * Core StorageManager/TenantStorage directly — it must use ModuleStorage::for() so
 * per-tenant files stay under tenant/<id>/<key>/.
 */
class ManifestLinterSourceTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zzlint_' . bin2hex(random_bytes(6));
        @mkdir($this->dir . DIRECTORY_SEPARATOR . 'src', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
        parent::tearDown();
    }

    private function writeSrc(string $name, string $code): void
    {
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $name, $code);
    }

    public function testFlagsDirectStorageManagerInstantiation(): void
    {
        $this->writeSrc('Bad.php', "<?php\n\$s = new StorageManager();\n\$s->write('foo/x', 'y');\n");
        $result = (new ManifestLinter())->lintSource($this->dir);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('StorageManager', $result['errors'][0]);
        $this->assertStringContainsString('Bad.php', $result['errors'][0]);
    }

    public function testFlagsFullyQualifiedAndTenantStorage(): void
    {
        $this->writeSrc('Fq.php', "<?php\n\$s = new \\App\\Service\\Storage\\TenantStorage();\n");
        $result = (new ManifestLinter())->lintSource($this->dir);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('TenantStorage', $result['errors'][0]);
    }

    public function testAcceptsModuleStorageHandle(): void
    {
        $this->writeSrc('Good.php', "<?php\n\$s = \\App\\Service\\Storage\\ModuleStorage::for('ticketing');\n\$s->write('a/b', 'c');\n");
        $result = (new ManifestLinter())->lintSource($this->dir);
        $this->assertSame([], $result['errors'], 'ModuleStorage::for is the sanctioned API');
    }

    public function testDoesNotFalsePositiveOnSimilarNames(): void
    {
        // A class merely *named* like the forbidden ones must not trip the rule.
        $this->writeSrc('Ok.php', "<?php\n\$s = new MyStorageManagerFactory();\n");
        $result = (new ManifestLinter())->lintSource($this->dir);
        $this->assertSame([], $result['errors']);
    }

    public function testNoSrcDirIsNoError(): void
    {
        $this->rrmdir($this->dir . DIRECTORY_SEPARATOR . 'src');
        $result = (new ManifestLinter())->lintSource($this->dir);
        $this->assertSame([], $result['errors']);
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
            $path = $dir . DIRECTORY_SEPARATOR . $f;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
