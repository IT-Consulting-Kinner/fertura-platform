<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use App\Service\I18n\LanguagePackAdmin;
use App\Service\I18n\LanguagePackStore;
use App\Test\TestCase\AdminAreaSeedTrait;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integration test for the language management GUI (admin area `localization`,
 * E41/E42): overview, editor (load/save), review, deletion rules (English
 * protection), and import validation/commit.
 */
class LocalizationControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use AdminAreaSeedTrait;

    private string $userId;
    private LanguagePackStore $store;

    private const PO = "msgid \"\"\nmsgstr \"\"\n\"Language: xx_LC\\n\"\n\n"
        . "msgid \"t.greet\"\nmsgstr \"Hallo\"\n\nmsgid \"t.world\"\nmsgstr \"Welt\"\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new LanguagePackStore(); // default base: the controller uses it internally
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "INSERT INTO admin_areas (area_key, label, sort_order) VALUES ('localization', 'Lang', 70) "
            . 'ON CONFLICT (area_key) DO NOTHING',
        );
        $this->userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_ladmin_' . bin2hex(random_bytes(3)), 'e' => 'ladmin_' . bin2hex(random_bytes(3)) . '@zzlang.local'],
        )->fetch('assoc')['id'];
        $this->grantAdminAreas($this->userId, 'localization');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        // Remove store files of the test locales (metadata does not cascade to files).
        $rows = $conn->execute(
            "SELECT component_key, version, locale, domain FROM language_packs WHERE locale LIKE 'xx_%'",
        )->fetchAll('assoc');
        foreach ($rows as $r) {
            @unlink($this->store->filePath((string)$r['component_key'], (string)$r['version'], (string)$r['locale'], (string)$r['domain']));
        }
        $conn->execute("DELETE FROM language_packs WHERE locale LIKE 'xx_%'");
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzlang.local'");
    }

    private function login(): void
    {
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_ladmin', 'email' => 'l@zzlang.local']]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
    }

    /** Creates a test pack through the (default) store. */
    private function seedPack(string $locale): void
    {
        (new LanguagePackAdmin($this->store))->importCommit(
            $this->tmpPo(self::PO),
            'core',
            'core',
            '1.0.0',
            $locale,
            'default',
            null,
        );
    }

    private function tmpPo(string $content): string
    {
        $path = TMP . 'zztest-lc-' . bin2hex(random_bytes(3)) . '.po';
        file_put_contents($path, $content);

        return $path;
    }

    private function target(string $locale): string
    {
        return 'component=core&version=1.0.0&locale=' . $locale . '&domain=default';
    }

    public function testIndexRendersOverview(): void
    {
        $this->seedPack('xx_LA');
        $this->login();
        $this->get('/admin/localization');

        $this->assertResponseOk();
        $this->assertResponseContains('xx_LA');
    }

    public function testEditRendersEntriesAndUnknownRedirects(): void
    {
        $this->seedPack('xx_LB');
        $this->login();

        $this->get('/admin/localization/edit?' . $this->target('xx_LB'));
        $this->assertResponseOk();
        $this->assertResponseContains('t.greet');

        $this->get('/admin/localization/edit?' . $this->target('xx_ZZ')); // does not exist
        $this->assertRedirect(['action' => 'index']);
    }

    public function testSavePersistsTranslations(): void
    {
        $this->seedPack('xx_LD');
        $this->login();
        $admin = new LanguagePackAdmin($this->store);
        $idx = $admin->entries('core', '1.0.0', 'xx_LD', 'default')[0]['index'];

        $this->post('/admin/localization/save?' . $this->target('xx_LD'), [
            'msgstr' => [$idx => ['Servus GUI']],
        ]);

        $this->assertRedirect(['action' => 'index']);
        $after = $admin->entries('core', '1.0.0', 'xx_LD', 'default');
        $this->assertSame('Servus GUI', $after[0]['msgstr'][0]);
        $this->assertTrue((bool)$admin->meta('core', '1.0.0', 'xx_LD')['edited']);
    }

    public function testReviewMarksPack(): void
    {
        $this->seedPack('xx_LE');
        $this->login();
        $this->post('/admin/localization/review?' . $this->target('xx_LE'));

        $this->assertRedirect(['action' => 'index']);
        $meta = (new LanguagePackAdmin($this->store))->meta('core', '1.0.0', 'xx_LE');
        $this->assertTrue((bool)$meta['reviewed']);
    }

    public function testDeleteRemovesPackButEnglishBlocked(): void
    {
        $this->seedPack('xx_LF');
        $this->login();

        $this->post('/admin/localization/delete?' . $this->target('xx_LF'));
        $this->assertRedirect(['action' => 'index']);
        $this->assertNull((new LanguagePackAdmin($this->store))->meta('core', '1.0.0', 'xx_LF'));

        // English on an active component: service throws, GUI catches -> flash + redirect.
        $this->post('/admin/localization/delete?component=core&version=1.0.0&locale=en_US&domain=default');
        $this->assertRedirect(['action' => 'index']);
    }

    public function testImportRejectsBadLocaleAndCommitImports(): void
    {
        $this->login();

        // GET: the upload form now lives on the index page (accordion) -> redirect.
        $this->get('/admin/localization/import');
        $this->assertRedirect(['action' => 'index']);

        // Invalid locale -> back to the index (accordion + flash).
        $this->post('/admin/localization/import', ['step' => 'preview', 'locale' => 'nope', 'component' => 'core', 'version' => '1.0.0']);
        $this->assertRedirect(['action' => 'index']);

        // Commit step: token file already sits in the import buffer (as after preview).
        $token = bin2hex(random_bytes(16));
        $tmpDir = TMP . 'langimport';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }
        file_put_contents($tmpDir . DIRECTORY_SEPARATOR . $token . '.po', self::PO);

        $this->post('/admin/localization/import', [
            'step' => 'commit', 'token' => $token, 'type' => 'core',
            'component' => 'core', 'version' => '1.0.0', 'locale' => 'xx_LG', 'domain' => 'default',
        ]);

        $this->assertRedirect(['action' => 'index']);
        $meta = (new LanguagePackAdmin($this->store))->meta('core', '1.0.0', 'xx_LG');
        $this->assertNotNull($meta);
        $this->assertSame('upload', $meta['source']);
    }
}
