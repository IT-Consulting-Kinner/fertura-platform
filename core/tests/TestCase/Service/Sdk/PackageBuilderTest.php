<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Sdk;

use App\Service\Sdk\PackageBuilder;
use App\Service\Security\PackageVerifier;
use Cake\TestSuite\TestCase;

/**
 * Release-assembly gates (E176): the package pre-step lints, enforces a monotonic
 * version + reversible migrations + a changelog, and emits a signable package dir
 * with a release.json. No DB needed — pure filesystem.
 */
class PackageBuilderTest extends TestCase
{
    private string $work = '';

    /** @var array<string,mixed> A lint-clean base manifest (from the sample_module fixture). */
    private array $baseManifest = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = sys_get_temp_dir() . '/zzpkg_' . bin2hex(random_bytes(5));
        mkdir($this->work, 0o775, true);
        /** @var array<string,mixed> $m */
        $m = json_decode((string)file_get_contents(ROOT . '/tests/Fixture/sample_module/manifest.json'), true);
        $this->baseManifest = $m;
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->work);
        parent::tearDown();
    }

    /**
     * @param array{down?:bool, changelog?:bool, version?:string} $opts
     */
    private function makeModule(array $opts = []): string
    {
        $version = $opts['version'] ?? '0.2.0';
        $dir = $this->work . '/src_' . bin2hex(random_bytes(3));
        mkdir($dir . '/migrations', 0o775, true);
        mkdir($dir . '/tests', 0o775, true); // dev-only -> must be excluded

        $manifest = $this->baseManifest;
        $manifest['version'] = $version;
        file_put_contents($dir . '/manifest.json', (string)json_encode($manifest, JSON_PRETTY_PRINT));

        $up = "CREATE TABLE x (id integer);\n";
        $withDown = $opts['down'] ?? true;
        $down = $withDown ? "-- @DOWN\nDROP TABLE IF EXISTS x;\n" : '';
        file_put_contents($dir . '/migrations/001_x.sql', $up . $down);

        if ($opts['changelog'] ?? true) {
            file_put_contents($dir . '/CHANGELOG.md', "# Changelog\n\n## $version\n- Initial test release.\n");
        }
        file_put_contents($dir . '/tests/SomeTest.php', "<?php // dev only\n");

        return $dir;
    }

    public function testBuildsSignablePackageWithReleaseManifest(): void
    {
        $res = (new PackageBuilder())->build($this->makeModule(), $this->work . '/out');

        $this->assertTrue($res['ok'], 'build should succeed: ' . implode('; ', $res['errors']));
        $stage = $res['path'];
        $this->assertDirectoryExists($stage);
        $this->assertFileExists($stage . '/manifest.json');
        $this->assertFileExists($stage . '/migrations/001_x.sql');

        // release.json present + correct.
        $this->assertFileExists($stage . '/release.json');
        /** @var array<string,mixed> $rel */
        $rel = json_decode((string)file_get_contents($stage . '/release.json'), true);
        $this->assertSame('0.2.0', $rel['version']);
        $this->assertTrue($rel['migrations'][0]['reversible']);
        $this->assertStringContainsString('Initial test release', (string)$rel['changelog']);

        // The staging dir is sign-able (the digest computes).
        $this->assertNotSame('', (new PackageVerifier())->packageDigest($stage));
    }

    public function testExcludesDevOnlyPaths(): void
    {
        $res = (new PackageBuilder())->build($this->makeModule(), $this->work . '/out');

        $this->assertTrue($res['ok']);
        $this->assertDirectoryDoesNotExist($res['path'] . '/tests'); // dev-only, never shipped
    }

    public function testRejectsIrreversibleMigration(): void
    {
        $res = (new PackageBuilder())->build($this->makeModule(['down' => false]), $this->work . '/out');

        $this->assertFalse($res['ok']);
        $this->assertNotEmpty(array_filter($res['errors'], static fn(string $e): bool => str_contains($e, '@DOWN')));
    }

    public function testRejectsNonMonotonicVersion(): void
    {
        $res = (new PackageBuilder())->build($this->makeModule(['version' => '0.1.0']), $this->work . '/out', '0.2.0');

        $this->assertFalse($res['ok']);
        $this->assertNotEmpty(array_filter($res['errors'], static fn(string $e): bool => str_contains($e, 'Monotonie')));
    }

    public function testRejectsMissingChangelogEntry(): void
    {
        $res = (new PackageBuilder())->build($this->makeModule(['changelog' => false]), $this->work . '/out');

        $this->assertFalse($res['ok']);
        $this->assertNotEmpty(array_filter($res['errors'], static fn(string $e): bool => str_contains($e, 'CHANGELOG')));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
