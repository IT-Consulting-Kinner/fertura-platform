<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\I18n;

use App\Application;
use App\Service\I18n\LanguagePackAdmin;
use App\Service\I18n\LanguagePackStore;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use RuntimeException;

/**
 * Tests the language-management orchestration (i18n-6, E41/E42): import
 * (preview + commit as an unsigned upload pack), lossless editing
 * (edited=reviewed=true), review, deletion rules (English protection while a
 * component is active) and overview/version status (clean/notice/error).
 */
class LanguagePackAdminTest extends TestCase
{
    private string $base;
    private LanguagePackAdmin $admin;
    private LanguagePackStore $store;

    private const PO = "msgid \"\"\nmsgstr \"\"\n\"Language: xx_AA\\n\"\n\n"
        . "msgid \"t.greet\"\nmsgstr \"Hallo\"\n\nmsgid \"t.world\"\nmsgstr \"Welt\"\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->base = TMP . 'zztest-langstore-' . bin2hex(random_bytes(3));
        $this->store = new LanguagePackStore($this->base);
        $this->admin = new LanguagePackAdmin($this->store);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->rmdirRecursive($this->base);
        parent::tearDown();
    }

    private function cleanup(): void
    {
        ConnectionManager::get('default')->execute(
            "DELETE FROM language_packs WHERE locale LIKE 'xx_%' OR component_key LIKE 'zztestlpa%'",
        );
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f);
        }
        @rmdir($dir);
    }

    private function tmpPo(string $content): string
    {
        $path = TMP . 'zztest-upload-' . bin2hex(random_bytes(3)) . '.po';
        file_put_contents($path, $content);

        return $path;
    }

    public function testImportPreviewValidatesAndDetectsExisting(): void
    {
        // Invalid: no msgid.
        $bad = $this->admin->importPreview($this->tmpPo('not a po file'), 'core', '1.0.0', 'xx_AA');
        $this->assertFalse($bad['ok']);

        // Valid, target does not exist yet.
        $ok = $this->admin->importPreview($this->tmpPo(self::PO), 'core', '1.0.0', 'xx_AA');
        $this->assertTrue($ok['ok']);
        $this->assertSame(2, $ok['count']);
        $this->assertFalse($ok['exists']);
        $this->assertContains('t.greet', $ok['sample']);

        // After commit + edit: exists + existing_edited.
        $this->admin->importCommit($this->tmpPo(self::PO), 'core', 'core', '1.0.0', 'xx_AA', 'default', null);
        $idx = $this->admin->entries('core', '1.0.0', 'xx_AA', 'default')[0]['index'];
        $this->admin->saveEntries('core', '1.0.0', 'xx_AA', 'default', [$idx => ['Servus']], null);

        $again = $this->admin->importPreview($this->tmpPo(self::PO), 'core', '1.0.0', 'xx_AA');
        $this->assertTrue($again['exists']);
        $this->assertTrue($again['existing_edited']);
    }

    public function testImportCommitStoresUnsignedUploadPack(): void
    {
        $this->admin->importCommit($this->tmpPo(self::PO), 'core', 'core', '1.0.0', 'xx_AB', 'default', null);

        $meta = $this->admin->meta('core', '1.0.0', 'xx_AB');
        $this->assertNotNull($meta);
        $this->assertSame('upload', $meta['source']);
        $this->assertFalse((bool)$meta['signed']);
        $this->assertFalse((bool)$meta['reviewed']);
        $this->assertFalse((bool)$meta['edited']);

        $entries = $this->admin->entries('core', '1.0.0', 'xx_AB', 'default');
        $this->assertCount(2, $entries);
        $this->assertSame('Hallo', $entries[0]['msgstr'][0]);
    }

    public function testSaveEntriesEditsLosslesslyAndMarksReviewed(): void
    {
        $this->admin->importCommit($this->tmpPo(self::PO), 'core', 'core', '1.0.0', 'xx_AC', 'default', null);
        $entries = $this->admin->entries('core', '1.0.0', 'xx_AC', 'default');

        $this->admin->saveEntries('core', '1.0.0', 'xx_AC', 'default', [
            $entries[0]['index'] => ['Servus'],
        ], null);

        $after = $this->admin->entries('core', '1.0.0', 'xx_AC', 'default');
        $this->assertSame('Servus', $after[0]['msgstr'][0]);
        $this->assertSame('Welt', $after[1]['msgstr'][0]); // unchanged (lossless)

        $meta = $this->admin->meta('core', '1.0.0', 'xx_AC');
        $this->assertTrue((bool)$meta['edited']);
        $this->assertTrue((bool)$meta['reviewed']); // admin edit = review (E38)
    }

    public function testReviewMarksWithoutContentChange(): void
    {
        $this->admin->importCommit($this->tmpPo(self::PO), 'core', 'core', '1.0.0', 'xx_AD', 'default', null);
        $this->admin->review('core', '1.0.0', 'xx_AD', 'default');

        $meta = $this->admin->meta('core', '1.0.0', 'xx_AD');
        $this->assertTrue((bool)$meta['reviewed']);
        $this->assertFalse((bool)$meta['edited']); // content untouched
    }

    public function testDeleteRules(): void
    {
        // Direct rule matrix (E41).
        $this->assertFalse($this->admin->mayDelete(true, 'en_US'));
        $this->assertTrue($this->admin->mayDelete(true, 'de_DE'));
        $this->assertTrue($this->admin->mayDelete(false, 'en_US'));

        // Core is always active -> en_US deletion is rejected (before any I/O).
        $this->expectException(RuntimeException::class);
        $this->admin->deletePack('core', '1.0.0', 'en_US', 'default');
    }

    public function testDeletePackRemovesFileAndMeta(): void
    {
        $this->admin->importCommit($this->tmpPo(self::PO), 'core', 'core', '1.0.0', 'xx_AE', 'default', null);
        $this->assertNotNull($this->store->read('core', '1.0.0', 'xx_AE', 'default'));

        $this->admin->deletePack('core', '1.0.0', 'xx_AE', 'default');
        $this->assertNull($this->store->read('core', '1.0.0', 'xx_AE', 'default'));
        $this->assertNull($this->admin->meta('core', '1.0.0', 'xx_AE'));
    }

    public function testDeleteEnglishAllowedForRemovedComponent(): void
    {
        // Component without a modules entry = removed/inactive -> English is deletable.
        $this->admin->importCommit($this->tmpPo(self::PO), 'module', 'zztestlpa_removed', '1.0.0', 'en_US', 'zztestlpa_removed', null);
        $this->admin->deletePack('zztestlpa_removed', '1.0.0', 'en_US', 'zztestlpa_removed');
        $this->assertNull($this->admin->meta('zztestlpa_removed', '1.0.0', 'en_US'));
    }

    public function testOverviewComputesVersionStatus(): void
    {
        // Active core version is Application::CORE_VERSION (1.0.0):
        // same version -> clean, same major -> notice, different major -> error.
        $this->admin->importCommit($this->tmpPo(self::PO), 'core', 'core', Application::CORE_VERSION, 'xx_AF', 'default', null);
        $this->admin->importCommit($this->tmpPo(self::PO), 'core', 'core', '1.9.9', 'xx_AF', 'default', null);
        $this->admin->importCommit($this->tmpPo(self::PO), 'core', 'core', '3.0.0', 'xx_AF', 'default', null);

        $core = null;
        foreach ($this->admin->overview() as $component) {
            if ($component['key'] === 'core') {
                $core = $component;
                break;
            }
        }
        $this->assertNotNull($core);
        $this->assertTrue($core['active']);

        $statusByVersion = [];
        foreach ($core['packs'] as $pack) {
            if ($pack['locale'] === 'xx_AF') {
                $statusByVersion[$pack['version']] = $pack['status'];
            }
        }
        $this->assertSame('clean', $statusByVersion[Application::CORE_VERSION]);
        $this->assertSame('notice', $statusByVersion['1.9.9']);
        $this->assertSame('error', $statusByVersion['3.0.0']);

        // en_US is not deletable while the component is active, other locales are.
        foreach ($core['packs'] as $pack) {
            if ($pack['locale'] === 'xx_AF') {
                $this->assertTrue($pack['deletable']);
            }
        }
    }

    public function testInstalledComponentsAlwaysContainsCore(): void
    {
        $components = $this->admin->installedComponents();
        $this->assertSame('core', $components[0]['key']);
        $this->assertSame(Application::CORE_VERSION, $components[0]['version']);
    }
}
