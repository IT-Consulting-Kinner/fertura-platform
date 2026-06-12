<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Service\Module\ModuleLifecycle;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Throwable;

/**
 * End-to-end test for the Core module **web-mount** dispatcher (ch. 23.16.3):
 * a module ships server-rendered pages (`web_routes` + handler + template), and
 * the Core mounts them at `/m/<key>/<path>` under its own layout, session auth,
 * area gating and RLS — without the module touching the Core.
 *
 * Proves: authed page renders the module template with handler vars; guest page
 * is public; unauthenticated access to a `user` page redirects to login; an
 * unknown path is 404.
 */
class ModuleWebTest extends TestCase
{
    use IntegrationTestTrait;

    private const KEY = 'zztest_web';

    private string $userId = '';
    private bool $prevRequireSig = true;

    protected function setUp(): void
    {
        parent::setUp();
        $sm = new SettingsManager();
        $this->prevRequireSig = (bool)$sm->get('core', 'require_module_signature', true);
        $sm->set('core', 'require_module_signature', false);

        $this->cleanupModule();

        // A real user for the authenticated page + RLS context.
        $conn = ConnectionManager::get('default');
        $this->userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_web_' . bin2hex(random_bytes(3)), 'e' => 'web_' . bin2hex(random_bytes(3)) . '@zztest.local'],
        )->fetch('assoc')['id'];

        $lc = new ModuleLifecycle();
        $lc->install(ROOT . '/tests/Fixture/zztest_web');
        $lc->activate(self::KEY);
    }

    protected function tearDown(): void
    {
        $this->cleanupModule();
        if ($this->userId !== '') {
            ConnectionManager::get('default')->execute('DELETE FROM users WHERE id = :id', ['id' => $this->userId]);
        }
        (new SettingsManager())->set('core', 'require_module_signature', $this->prevRequireSig);
        parent::tearDown();
    }

    public function testAuthenticatedPageRendersModuleTemplate(): void
    {
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_web', 'email' => 'w@zztest.local']]);
        $this->get('/m/zztest_web/dashboard');

        $this->assertResponseOk();
        $this->assertResponseContains('Hallo aus dem Modul');     // module template content
        $this->assertResponseContains($this->userId);             // handler received the user id
        $this->assertResponseContains('/dashboard');              // handler received the path
        $this->assertResponseContains('Fertura');                 // Core layout wraps the page
    }

    public function testGuestPageRendersWithoutLogin(): void
    {
        $this->get('/m/zztest_web/public');

        $this->assertResponseOk();
        $this->assertResponseContains('Oeffentliche Modulseite');
    }

    public function testUserPageRedirectsToLoginWhenUnauthenticated(): void
    {
        $this->get('/m/zztest_web/dashboard');

        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testUnknownPathIs404(): void
    {
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_web', 'email' => 'w@zztest.local']]);
        $this->get('/m/zztest_web/does-not-exist');

        $this->assertResponseCode(404);
    }

    private function cleanupModule(): void
    {
        try {
            (new ModuleLifecycle())->delete(self::KEY);
        } catch (Throwable) {
            // Not installed — fall through to belt-and-suspenders cleanup.
        }
        try {
            ConnectionManager::get('default')->execute('DELETE FROM modules WHERE module_key = :k', ['k' => self::KEY]);
        } catch (Throwable) {
        }
        $this->rrmdir(ROOT . '/modules/' . self::KEY);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
