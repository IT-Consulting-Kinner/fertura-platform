<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Service\Module\ModuleLifecycle;
use App\Service\Settings\SettingsManager;
use App\Service\Storage\StorageManager;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\ResponseInterface;
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
        Configure::delete('Cors.allowedOrigins');
        try {
            (new StorageManager())->delete('reports/zztest_dl.txt');
        } catch (Throwable) {
            // Not written in this test — ignore.
        }
        $this->cleanupModule();
        if ($this->userId !== '') {
            $conn = ConnectionManager::get('default');
            $conn->execute('DELETE FROM user_admin_areas WHERE user_id = :id', ['id' => $this->userId]);
            $conn->execute('DELETE FROM users WHERE id = :id', ['id' => $this->userId]);
        }
        (new SettingsManager())->set('core', 'require_module_signature', $this->prevRequireSig);
        parent::tearDown();
    }

    public function testAuthenticatedPageRendersModuleTemplate(): void
    {
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_web', 'email' => 'w@zztest.local']]);
        $this->get('/m/zztest_web/dashboard');

        $this->assertResponseOk();
        $this->assertResponseContains('Hallo aus dem Modul'); // module template content
        $this->assertResponseContains($this->userId); // handler received the user id
        $this->assertResponseContains('/dashboard'); // handler received the path
        $this->assertResponseContains('Fertura'); // Core layout wraps the page
    }

    public function testGuestPageRendersWithoutLogin(): void
    {
        $this->get('/m/zztest_web/public');

        $this->assertResponseOk();
        $this->assertResponseContains('Oeffentliche Modulseite');
    }

    public function testGuestHandlerReceivesClientIp(): void
    {
        // E174: the dispatcher passes the client IP into the module web request so
        // a public/guest handler can rate-limit (e.g. the Ticketing guest portal).
        $this->configRequest(['environment' => ['REMOTE_ADDR' => '203.0.113.7']]);
        $this->get('/m/zztest_web/public');

        $this->assertResponseOk();
        $this->assertResponseContains('data-test="ip">203.0.113.7<'); // handler saw the IP
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

    public function testAdminPageRendersInAdminShellWithNavEntry(): void
    {
        // Grant the user the module-defined admin area.
        ConnectionManager::get('default')->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $this->userId, 'a' => 'zztest_web_admin'],
        );
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_web', 'email' => 'w@zztest.local']]);
        $this->get('/m/zztest_web/admin');

        $this->assertResponseOk();
        $this->assertResponseContains('Modul-Admin-Seite'); // module template content
        $this->assertResponseContains('sidebar'); // rendered in the ADMIN shell
        $this->assertResponseContains('/m/zztest_web/admin'); // module nav item links to the page
        $this->assertResponseContains('zztest.nav.config'); // module's sidebar item label
    }

    public function testAdminPageForbiddenWithoutArea(): void
    {
        // Logged in, but the user does NOT hold the module's admin area.
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_web', 'email' => 'w@zztest.local']]);
        $this->get('/m/zztest_web/admin');

        $this->assertResponseCode(403);
    }

    public function testPublicApiEndpointAllowsNoToken(): void
    {
        // No Bearer token, no session: a `public` module API endpoint is reached
        // (the Core passes through; the module owns its own auth, Decision D1/D2).
        $this->configRequest(['headers' => ['Accept' => 'application/json', 'X-Module-Token' => 'queue-tok-123']]);
        $this->get('/api/v1/m/zztest_web/status');

        $this->assertResponseOk();
        $this->assertResponseContains('"ok":true');
        $this->assertResponseContains('"saw_module_token":true'); // module read its own header
    }

    public function testUserApiEndpointStillRequiresToken(): void
    {
        // A `user`-auth module endpoint (default) still requires a Core token.
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/v1/m/zztest_web/secure');

        $this->assertResponseCode(401);
        $this->assertResponseContains('missing_token');
    }

    public function testPublicApiCacheHeadersPassThroughButNotSetCookie(): void
    {
        // E160: a `public` route may make its content cacheable; only allowlisted
        // headers pass through (Set-Cookie must not).
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/v1/m/zztest_web/status');

        $this->assertResponseOk();
        $this->assertHeader('Cache-Control', 'public, max-age=300');
        $this->assertHeader('ETag', '"v1"');
        // E175: Retry-After passes through so a public module route can signal
        // 429/503 backoff for its own rate-limiting (e.g. the KB public API).
        $this->assertHeader('Retry-After', '30');
        // Set-Cookie is not allowlisted -> the module cannot smuggle it through
        // ('' when absent, never the module's value).
        $this->assertStringNotContainsString('evil', $this->response()->getHeaderLine('Set-Cookie'));
    }

    /** The integration response, guaranteed non-null (PHPStan-safe). */
    private function response(): ResponseInterface
    {
        $response = $this->_response;
        if ($response === null) {
            $this->fail('No response set.');
        }

        return $response;
    }

    public function testCorsHeaderForAllowedOriginOnPublicRoute(): void
    {
        Configure::write('Cors.allowedOrigins', 'https://help.kunde.test');
        $this->configRequest(['headers' => [
            'Accept' => 'application/json',
            'Origin' => 'https://help.kunde.test',
        ]]);
        $this->get('/api/v1/m/zztest_web/status');

        $this->assertResponseOk();
        $this->assertHeader('Access-Control-Allow-Origin', 'https://help.kunde.test');
    }

    public function testNoCorsHeaderForDisallowedOrigin(): void
    {
        Configure::write('Cors.allowedOrigins', 'https://help.kunde.test');
        $this->configRequest(['headers' => [
            'Accept' => 'application/json',
            'Origin' => 'https://evil.test',
        ]]);
        $this->get('/api/v1/m/zztest_web/status');

        $this->assertResponseOk();
        $this->assertFalse($this->response()->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testCorsPreflightAnsweredForPublicRoute(): void
    {
        Configure::write('Cors.allowedOrigins', 'https://help.kunde.test');
        $this->configRequest(['headers' => [
            'Origin' => 'https://help.kunde.test',
            'Access-Control-Request-Method' => 'GET',
        ]]);
        $this->options('/api/v1/m/zztest_web/status');

        $this->assertResponseCode(204);
        $this->assertHeader('Access-Control-Allow-Origin', 'https://help.kunde.test');
    }

    public function testNoCorsOnUserRoute(): void
    {
        // A `user` route never gets CORS, even with a wildcard allow-list.
        Configure::write('Cors.allowedOrigins', '*');
        $this->configRequest(['headers' => [
            'Accept' => 'application/json',
            'Origin' => 'https://help.kunde.test',
        ]]);
        $this->get('/api/v1/m/zztest_web/secure');

        $this->assertResponseCode(401);
        $this->assertFalse($this->response()->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testWebMountDownloadsInMemoryContent(): void
    {
        // E161 (a): a web-mount handler returns in-memory bytes as a file download.
        $this->get('/m/zztest_web/download');

        $this->assertResponseOk();
        $this->assertHeaderContains('Content-Disposition', 'inline.csv');
        $this->assertHeaderContains('Content-Type', 'text/csv');
        $this->assertResponseEquals("a,b\n1,2\n");
    }

    public function testWebMountStreamsDownloadFromStorage(): void
    {
        // E161 (b): a stored report is streamed from object storage (no memory load).
        $this->get('/m/zztest_web/download?mode=stream');

        $this->assertResponseOk();
        $this->assertHeaderContains('Content-Disposition', 'report.txt');
        $this->assertHeader('Content-Length', '20');
        $this->assertResponseEquals('streamed-content-xyz');
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
