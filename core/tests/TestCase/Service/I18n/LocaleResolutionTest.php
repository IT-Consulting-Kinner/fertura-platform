<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\I18n;

use App\Application;
use App\Service\I18n\LanguagePackStore;
use App\Service\I18n\LocaleResolver;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Integration test of the i18n version gate (ch. 31, E39) against the test DB +
 * managed locale store: packs are persisted for real (store write + metadata
 * in core.language_packs), then resolution is checked for exact / same-major /
 * mismatch — the real LocaleResolver path.
 */
class LocaleResolutionTest extends TestCase
{
    private const COMPONENT = 'ztest_i18n';
    private string $storeBase = '';
    private LocaleResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storeBase = sys_get_temp_dir() . '/fertura_i18ntest_' . bin2hex(random_bytes(5));
        $store = new LanguagePackStore($this->storeBase);
        $this->resolver = new LocaleResolver();

        // Three de_DE packs of the same component: 1.0.0, 1.5.0, 2.0.0.
        foreach (['1.0.0', '1.5.0', '2.0.0'] as $version) {
            $store->save(self::COMPONENT, $version, 'de_DE', $this->po($version), [
                'type' => 'module',
                'domain' => self::COMPONENT,
                'signed' => false,
                'reviewed' => true,
                'source' => 'upload',
            ]);
        }
    }

    protected function tearDown(): void
    {
        ConnectionManager::get('default')->execute(
            'DELETE FROM language_packs WHERE component_key = :k',
            ['k' => self::COMPONENT],
        );
        $this->rrmdir($this->storeBase);
        parent::tearDown();
    }

    public function testExactVersionIsClean(): void
    {
        $r = $this->resolver->resolveVersion(self::COMPONENT, '1.0.0', 'de_DE');
        $this->assertSame(['version' => '1.0.0', 'status' => 'clean'], $r);
    }

    public function testSameMajorPicksHighestWithNotice(): void
    {
        // Active 1.9.0: no exact pack -> highest same-major (1.5.0), notice.
        $r = $this->resolver->resolveVersion(self::COMPONENT, '1.9.0', 'de_DE');
        $this->assertSame(['version' => '1.5.0', 'status' => 'notice'], $r);
    }

    public function testMajorMismatchFallsBack(): void
    {
        // Active 3.0.0: no major-3 pack -> null (caller falls back to English).
        $this->assertNull($this->resolver->resolveVersion(self::COMPONENT, '3.0.0', 'de_DE'));
    }

    public function testUnknownLocaleFallsBack(): void
    {
        $this->assertNull($this->resolver->resolveVersion(self::COMPONENT, '1.0.0', 'fr_FR'));
    }

    public function testPackStatusesClassifiesEach(): void
    {
        $statuses = $this->resolver->packStatuses(self::COMPONENT, '1.5.0');
        $map = [];
        foreach ($statuses as $s) {
            $map[$s['version']] = $s['status'];
        }
        $this->assertSame('notice', $map['1.0.0']); // same major, different version
        $this->assertSame('clean', $map['1.5.0']);  // exactly active
        $this->assertSame('error', $map['2.0.0']);  // different major
    }

    public function testSelectableAlwaysIncludesEnglishAndFiltersUnavailable(): void
    {
        $sel = $this->resolver->selectableLocales(['en_US', 'zz_ZZ'], Application::CORE_VERSION);
        $this->assertContains('en_US', $sel);
        $this->assertNotContains('zz_ZZ', $sel, 'Nicht verfügbare Locale muss herausgefiltert werden.');
    }

    private function po(string $version): string
    {
        return "msgid \"\"\nmsgstr \"\"\n\"Project-Id-Version: $version\\n\"\n\n"
            . "msgid \"greeting\"\nmsgstr \"Hallo ($version)\"\n";
    }

    private function rrmdir(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
