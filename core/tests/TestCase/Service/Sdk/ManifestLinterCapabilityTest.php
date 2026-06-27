<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Sdk;

use App\Service\Sdk\ManifestLinter;
use Cake\TestSuite\TestCase;

/**
 * Test for the Inc 10 capability allowlist (ManifestLinter::lintSource): an in-process
 * module's src must not use dangerous primitives (shell/eval/reflection/raw FS/raw DB),
 * while the legitimate patterns the real modules use stay allowed.
 */
class ManifestLinterCapabilityTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zzcap_' . bin2hex(random_bytes(6));
        @mkdir($this->dir . DIRECTORY_SEPARATOR . 'src', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/src/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/src');
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @return list<string> the 'Primitiv' (capability) errors for a src snippet */
    private function capErrors(string $code): array
    {
        file_put_contents($this->dir . '/src/X.php', "<?php\n" . $code);
        $errors = (new ManifestLinter())->lintSource($this->dir)['errors'];

        return array_values(array_filter($errors, static fn($e) => str_contains($e, 'Primitiv')));
    }

    public function testForbiddenPrimitivesAreFlagged(): void
    {
        $forbidden = [
            '$out = exec("ls");',
            'system("rm -rf /");',
            'eval($code);',
            '$x = file_get_contents("/etc/passwd");',
            'file_put_contents("/tmp/x", $d);',
            '$h = fopen("/tmp/x", "w");',
            'unlink("/tmp/x");',
            'shell_exec("id");',
            'proc_open($cmd, $d, $p);',
            '$p = new \\PDO($dsn);',
            '$r = new ReflectionClass($c);',
            '$m->setAccessible(true);',
            '$$name = 1;',
            'ini_set("memory_limit", "-1");',
            'putenv("X=1");',
            'mkdir("/tmp/x");',
        ];
        foreach ($forbidden as $code) {
            $this->assertNotEmpty($this->capErrors($code), "should flag: $code");
        }
    }

    public function testLegitimatePatternsAreAllowed(): void
    {
        $allowed = [
            '$c = ConnectionManager::get("default");',
            'Db::exec("SELECT 1");',
            '$this->db->exec("SELECT 1");',
            'public function exec(string $s): void {}',
            'fwrite($this->stream, $data);',
            '$line = fgets($this->stream);',
            '$s = preg_replace("/[^a-z]/u", "", $x);',
            '$this->assertSame(1, $x);',
            '$sock = stream_socket_client($url, $e, $s, 5);',
        ];
        foreach ($allowed as $code) {
            $this->assertSame([], $this->capErrors($code), "should allow: $code");
        }
    }
}
