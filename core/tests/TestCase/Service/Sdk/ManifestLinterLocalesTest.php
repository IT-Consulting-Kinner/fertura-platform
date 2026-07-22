<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Sdk;

use App\Service\Sdk\ManifestLinter;
use Cake\TestSuite\TestCase;

/**
 * Test for the advisory locales scan: an author-time nudge that warns when a module's
 * PO catalogs use ICU placeholders (`{0}`/`{1}`), which the sprintf-pinned module i18n
 * loader ({@see \App\I18n\StoreLocaleLoader}) never substitutes (renders raw).
 */
class ManifestLinterLocalesTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zzloc_' . bin2hex(random_bytes(6));
        @mkdir($this->dir . '/locales/de_DE', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/locales/de_DE/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/locales/de_DE');
        @rmdir($this->dir . '/locales');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function writePo(string $name, string $content): void
    {
        file_put_contents($this->dir . '/locales/de_DE/' . $name, $content);
    }

    public function testSprintfCatalogIsNotWarned(): void
    {
        $this->writePo('mod.po', "msgid \"a\"\nmsgstr \"%s ist erforderlich.\"\n");
        $result = (new ManifestLinter())->lintLocales($this->dir);
        $this->assertSame([], $result['warnings']);
        $this->assertSame([], $result['errors']);
    }

    public function testIcuPlaceholderWarns(): void
    {
        $this->writePo('mod.po', "msgid \"a\"\nmsgstr \"{0} ist erforderlich.\"\nmsgid \"b\"\nmsgstr \"„{0}\" gespeichert ({1}).\"\n");
        $result = (new ManifestLinter())->lintLocales($this->dir);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('mod.po', $result['warnings'][0]);
        $this->assertStringContainsString('ICU-Platzhalter', $result['warnings'][0]);
        // Two offending msgstr lines in the one file.
        $this->assertStringContainsString('2 msgstr', $result['warnings'][0]);
        // Advisory only — never a hard error.
        $this->assertSame([], $result['errors']);
    }

    public function testBraceOnlyInMsgidIsNotWarned(): void
    {
        // The ICU token lives in the msgid (source key), not the translation — no render impact.
        $this->writePo('mod.po', "msgid \"tpl.{0}\"\nmsgstr \"Vorlage\"\n");
        $result = (new ManifestLinter())->lintLocales($this->dir);
        $this->assertSame([], $result['warnings']);
    }

    public function testNoLocalesDirIsNoWarning(): void
    {
        @rmdir($this->dir . '/locales/de_DE');
        @rmdir($this->dir . '/locales');
        $result = (new ManifestLinter())->lintLocales($this->dir);
        $this->assertSame([], $result['warnings']);
    }
}
