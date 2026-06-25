<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Sdk;

use App\Service\Sdk\ManifestLinter;
use Cake\TestSuite\TestCase;

/**
 * Test for the advisory migrations scan (Inc 9d): an author-time nudge that warns on a
 * created table which never gets RLS across the migration set and is not declared
 * module-global. The authoritative check remains the install gate.
 */
class ManifestLinterMigrationsTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zzmig_' . bin2hex(random_bytes(6));
        @mkdir($this->dir . DIRECTORY_SEPARATOR . 'migrations', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/migrations/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/migrations');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function writeMig(string $name, string $sql): void
    {
        file_put_contents($this->dir . '/migrations/' . $name, $sql);
    }

    public function testTableWithRlsIsNotWarned(): void
    {
        $this->writeMig('001.sql', 'CREATE TABLE tickets (id uuid PRIMARY KEY);');
        $this->writeMig('002.sql', 'ALTER TABLE tickets ENABLE ROW LEVEL SECURITY;');
        $result = (new ManifestLinter())->lintMigrations($this->dir, []);
        $this->assertSame([], $result['warnings']);
    }

    public function testHelperBuiltTableIsNotWarned(): void
    {
        $this->writeMig('001.sql', "SELECT core.create_tenant_table('mod_x', 'widgets', 'id uuid primary key');");
        $result = (new ManifestLinter())->lintMigrations($this->dir, []);
        $this->assertSame([], $result['warnings']);
    }

    public function testTableWithoutRlsAndNotGlobalWarns(): void
    {
        $this->writeMig('001.sql', 'CREATE TABLE system_settings (id uuid PRIMARY KEY, k text);');
        $result = (new ManifestLinter())->lintMigrations($this->dir, []);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('system_settings', $result['warnings'][0]);
    }

    public function testTableDeclaredGlobalIsNotWarned(): void
    {
        $this->writeMig('001.sql', 'CREATE TABLE system_settings (id uuid PRIMARY KEY, k text);');
        $result = (new ManifestLinter())->lintMigrations($this->dir, ['system_settings']);
        $this->assertSame([], $result['warnings']);
    }

    public function testNoMigrationsDirIsNoWarning(): void
    {
        @rmdir($this->dir . '/migrations');
        $result = (new ManifestLinter())->lintMigrations($this->dir, []);
        $this->assertSame([], $result['warnings']);
    }
}
