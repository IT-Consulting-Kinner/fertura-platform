<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\I18n;

use App\Application;
use App\Service\I18n\LanguagePackStore;
use App\Service\I18n\LocaleResolver;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Integrationstest des i18n-Versions-Gates (Kap. 31, E39) gegen die Test-DB +
 * Managed Locale Store: Packs werden real abgelegt (Store-Schreiben + Metadaten
 * in core.language_packs), dann die Auflösung exakt / same-major / mismatch
 * geprüft — der echte LocaleResolver-Pfad.
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

        // Drei de_DE-Packs derselben Komponente: 1.0.0, 1.5.0, 2.0.0.
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
        // Aktiv 1.9.0: kein exaktes Pack -> höchstes Same-Major (1.5.0), Hinweis.
        $r = $this->resolver->resolveVersion(self::COMPONENT, '1.9.0', 'de_DE');
        $this->assertSame(['version' => '1.5.0', 'status' => 'notice'], $r);
    }

    public function testMajorMismatchFallsBack(): void
    {
        // Aktiv 3.0.0: kein Major-3-Pack -> null (Aufrufer nutzt Englisch).
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
        $this->assertSame('notice', $map['1.0.0']); // same major, andere Version
        $this->assertSame('clean', $map['1.5.0']);  // exakt aktiv
        $this->assertSame('error', $map['2.0.0']);  // andere Major
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
