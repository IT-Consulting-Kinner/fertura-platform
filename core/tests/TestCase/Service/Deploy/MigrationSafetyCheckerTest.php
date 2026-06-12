<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Deploy;

use App\Service\Deploy\MigrationSafetyChecker;
use Cake\TestSuite\TestCase;

/**
 * Test of the migration safety check (P15): destructive up() patterns are
 * detected, while the down() rollback is ignored.
 */
class MigrationSafetyCheckerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/fertura_mig_' . bin2hex(random_bytes(5));
        @mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function write(string $name, string $content): void
    {
        file_put_contents($this->dir . '/' . $name, $content);
    }

    public function testFlagsDestructiveUpPatterns(): void
    {
        $this->write('001_bad.php', "<?php\nfunction up() {\n  \$this->execute('ALTER TABLE t DROP COLUMN c');\n  \$this->execute('ADD COLUMN x int NOT NULL');\n}\nfunction down() {\n  \$this->execute('DROP TABLE t');\n}\n");

        $findings = (new MigrationSafetyChecker())->check($this->dir);
        $issues = array_column($findings, 'issue');

        $this->assertContains('DROP COLUMN', $issues);
        $this->assertContains('NOT NULL ohne DEFAULT', $issues);
        // DROP TABLE only appears in down() -> not reported.
        $this->assertNotContains('DROP TABLE', $issues);
    }

    public function testCleanMigrationHasNoFindings(): void
    {
        $this->write('002_ok.php', "<?php\nfunction up() {\n  \$this->execute('ADD COLUMN x int NOT NULL DEFAULT 0');\n  \$this->execute('CREATE INDEX ix ON t (x)');\n}\n");
        $this->assertSame([], (new MigrationSafetyChecker())->check($this->dir));
    }
}
