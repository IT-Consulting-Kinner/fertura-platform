<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Service\Api\TokenService;
use App\Service\Module\ModuleLifecycle;
use App\Service\Module\TenantModuleService;
use App\Service\Settings\SettingsManager;
use App\Service\Storage\StorageManager;
use App\Test\TestCase\AdminAreaSeedTrait;
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
    use AdminAreaSeedTrait;

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

        // Per-tenant module enablement (Increment 5.1) is strict opt-in / fail-closed:
        // a freshly-activated module has no grant row, so its authenticated pages
        // would 404 for this tenant. Grant it to the test user's tenant so the
        // web-mount pages under test are reachable (guest pages are never gated).
        $tenantId = (string)$conn->execute(
            'SELECT tenant_id FROM users WHERE id = :id',
            ['id' => $this->userId],
        )->fetch('assoc')['tenant_id'];
        (new TenantModuleService())->enable($tenantId, self::KEY);
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

    public function testStandalonePageUsesLocaleDropdown(): void
    {
        // Regression fix: the standalone module layout uses the same locale DROPDOWN
        // (a <select>) as the admin shell — it previously rendered the legacy
        // side-by-side buttons because it called the switcher without the 'select' style.
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_web', 'email' => 'w@zztest.local']]);
        $this->get('/m/zztest_web/dashboard');

        $this->assertResponseOk();
        $this->assertResponseContains('locale-select'); // <select> dropdown, not buttons
    }

    public function testWebHandlerReceivesPerTenantConfig(): void
    {
        // Increment 5 Phase 3: the Core injects the tenant's stored module config
        // into the handler request; the dashboard fixture echoes greeting_suffix.
        $tenantId = (string)ConnectionManager::get('default')->execute(
            'SELECT tenant_id FROM users WHERE id = :id',
            ['id' => $this->userId],
        )->fetch('assoc')['tenant_id'];
        (new TenantModuleService())->setConfig($tenantId, self::KEY, ['greeting_suffix' => 'XYZ-CONF']);

        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_web', 'email' => 'w@zztest.local']]);
        $this->get('/m/zztest_web/dashboard');

        $this->assertResponseOk();
        $this->assertResponseContains('data-test="config">XYZ-CONF<');
    }

    public function testDisabledModuleHidesAuthenticatedPageButNotGuestPage(): void
    {
        // Fail-closed per-tenant gate (Increment 5.1): with the module DISABLED for
        // this tenant, an authenticated module page is 404 — indistinguishable from
        // a module that does not exist on the platform. A public/guest page stays
        // reachable, because the gate applies only to authenticated tenant use, not
        // to public entry points (e.g. a KB/ticket portal).
        $tenantId = (string)ConnectionManager::get('default')->execute(
            'SELECT tenant_id FROM users WHERE id = :id',
            ['id' => $this->userId],
        )->fetch('assoc')['tenant_id'];
        (new TenantModuleService())->disable($tenantId, self::KEY);

        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_web', 'email' => 'w@zztest.local']]);
        $this->get('/m/zztest_web/dashboard');
        $this->assertResponseCode(404);

        $this->get('/m/zztest_web/public');
        $this->assertResponseOk();
        $this->assertResponseContains('Oeffentliche Modulseite');
    }

    public function testModuleShellShipsSharedConfirmWiring(): void
    {
        // Operator module pages drive destructive actions through the shared
        // Bootstrap confirm modal ([data-confirm] / UiKit->confirmPost). The module
        // shell must therefore ship the same three pieces as the admin shell, else
        // a [data-confirm] control would submit with no prompt at all.
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_web', 'email' => 'w@zztest.local']]);
        $this->get('/m/zztest_web/dashboard');

        $this->assertResponseOk();
        $this->assertResponseContains('id="confirmModal"'); // generic confirm modal element
        $this->assertResponseContains('bootstrap.bundle.min'); // Bootstrap JS (modal/dropdown/tooltip)
        $this->assertResponseContains('js/ui.js'); // [data-confirm] -> modal wiring
    }

    public function testWebHandlerCanReturnJson(): void
    {
        // Options refresh for UiKit reference fields (MODULE_UI.md): a web
        // handler may answer with `json` instead of a template. The response
        // runs through session auth + the per-tenant gates and the RLS context,
        // so the options stay tenant-scoped like any web page.
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_web', 'email' => 'w@zztest.local']]);
        $this->get('/m/zztest_web/options?q=Kw7');

        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $this->assertResponseContains('"value":"a1"');
        $this->assertResponseContains('Beta Kw7'); // handler saw the query params
    }

    public function testPageSpecRendersViaCoreRenderer(): void
    {
        // Page spec (docs/module-page-spec-design.md): the handler returns a
        // declarative `page` instead of a template; the Core renders the
        // sections via templates/ModulePage/render.php. The fixture spec also
        // carries hostile bits (callables, off-origin templates, an unknown
        // section type) that must be dropped without breaking the page.
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_web', 'email' => 'w@zztest.local']]);
        $this->get('/m/zztest_web/spec');

        $this->assertResponseOk();
        // alert section
        $this->assertResponseContains('alert-warning');
        $this->assertResponseContains('Keine Mailbox konfiguriert.');
        // filters section: field renders with the current value + custom submit.
        $this->assertResponseContains('Sieben-Filter');
        $this->assertResponseContains('Filtern!');
        // table section: rows render, cell data is escaped, link_template expands.
        $this->assertResponseContains('Sieben');
        $this->assertResponseContains('Acht &amp; Co');
        $this->assertResponseContains('href="/m/zztest_web/things/7"');
        $this->assertResponseContains('href="/m/zztest_web/spec?edit=7"'); // action url_template
        // Paginate must target THIS page (review finding: with $url=null the
        // links resolved to the bare /module-web/dispatch fallback -> 500).
        $this->assertResponseContains('href="/m/zztest_web/spec?page=1"');
        $this->assertResponseNotContains('/module-web/dispatch');
        // v1.1: per-row POST action — inline form targeting the expanded
        // url_template, routed through the shared confirm modal.
        $this->assertResponseContains('action="/m/zztest_web/spec/toggle/7"');
        $this->assertResponseContains('data-confirm="Wirklich deaktivieren?"');
        // form_accordion: Core opens the form -> CSRF token present; collapsed
        // default; without 'url' the form posts back to the current page.
        $this->assertResponseContains('accordion-button collapsed');
        $this->assertResponseContains('name="_csrfToken"');
        $this->assertResponseContains('action="/m/zztest_web/spec"');
        $this->assertResponseContains('Anlegen');
        // v1.1: hidden dispatch field renders as a bare hidden input.
        $this->assertResponseContains('name="action" value="create"');
        // reference field inside the spec form survives coercion.
        $this->assertResponseContains('data-options-refresh="/m/zztest_web/options"');
        // detail section
        $this->assertResponseContains('<dt class="col-sm-3">Name</dt>');
        // html slot passes through raw
        $this->assertResponseContains('<div data-test="raw">RAW-OK</div>');
        // hostile bits dropped: no off-origin URL anywhere in the page.
        $this->assertResponseNotContains('evil.test');
        // Core layout wraps the page (standalone module shell).
        $this->assertResponseContains('Fertura');
    }

    public function testPageSpecOnAdminRouteRendersInAdminShellWithBreadcrumb(): void
    {
        // A page-spec route MAY declare an `area`: the spec then renders inside
        // the admin shell with the same default breadcrumb a template page gets
        // (shared AdminNavBuilder trail + page title as the active crumb).
        $this->grantAdminAreas($this->userId, 'zztest_web_admin');
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_web', 'email' => 'w@zztest.local']]);
        $this->get('/m/zztest_web/admin/spec');

        $this->assertResponseOk();
        $this->assertResponseContains('accordion-button collapsed'); // spec section renders
        $this->assertResponseContains('zztest.nav.group'); // admin shell nav present
        $this->assertResponseContains('Spec-Seite'); // handler title = active crumb
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
        $this->grantAdminAreas($this->userId, 'zztest_web_admin');
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_web', 'email' => 'w@zztest.local']]);
        $this->get('/m/zztest_web/admin');

        $this->assertResponseOk();
        $this->assertResponseContains('Modul-Admin-Seite'); // module template content
        $this->assertResponseContains('top-nav'); // rendered in the ADMIN shell (top menu)
        $this->assertResponseContains('/m/zztest_web/admin'); // module nav entry links to the page
        $this->assertResponseContains('zztest.nav.group'); // module's nav group label in the top menu
        // Regression fix: a module admin page gets a Core breadcrumb (Module -> page)
        // so it has navigation context back to the module area, not a bare shell.
        $this->assertResponseContains('aria-label="breadcrumb"');
        $this->assertResponseContains('/admin/module'); // breadcrumb links back to the Module landing
    }

    public function testAdminPageForbiddenWithoutArea(): void
    {
        // Logged in, but the user does NOT hold the module's admin area.
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_web', 'email' => 'w@zztest.local']]);
        $this->get('/m/zztest_web/admin');

        $this->assertResponseCode(403);
    }

    public function testModuleUniqueViolationBecomesWarningViaNet(): void
    {
        // A module page (DupPage) inserts a uniquely-constrained row WITHOUT its own
        // pre-check / catch / ON CONFLICT. The FIRST create succeeds; the DUPLICATE
        // raises a raw 23505 that the module dispatcher now routes to
        // UniqueViolationMiddleware (a warning + 303 redirect) instead of masking it
        // as a generic 500 — proving the net covers MODULE writes, not just Core ones.
        $this->grantAdminAreas($this->userId, 'zztest_web_admin');
        $this->session(['Auth' => ['id' => $this->userId, 'username' => 'zztest_web', 'email' => 'w@zztest.local']]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/m/zztest_web/admin/dup', ['action' => 'create', 'name' => 'zzunique']);
        $this->assertResponseSuccess(); // first create -> handler redirect, no error

        // The duplicate must NOT be a 500: the net turns the module 23505 into a warning.
        $this->post('/m/zztest_web/admin/dup', ['action' => 'create', 'name' => 'zzunique']);
        $this->assertResponseCode(303);
        $this->assertFlashElement('flash/warning');
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

    public function testPublicApiEndpointReceivesClientIp(): void
    {
        // E174: the API dispatcher passes the client IP through (like the
        // web-mount dispatcher already did), so a module can run its own per-IP
        // throttle on public endpoints (e.g. KB /search + /feedback). Without
        // this, `$request['client_ip']` was simply absent and every module-side
        // per-IP limiter was silently inactive on the API path.
        $this->configRequest([
            'headers' => ['Accept' => 'application/json'],
            'environment' => ['REMOTE_ADDR' => '203.0.113.9'],
        ]);
        $this->get('/api/v1/m/zztest_web/status');

        $this->assertResponseOk();
        $this->assertResponseContains('"client_ip":"203.0.113.9"');
    }

    public function testUserApiEndpointStillRequiresToken(): void
    {
        // A `user`-auth module endpoint (default) still requires a Core token.
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/v1/m/zztest_web/secure');

        $this->assertResponseCode(401);
        $this->assertResponseContains('missing_token');
    }

    public function testUserApiEndpointGatedByTenantEnablement(): void
    {
        // Increment 5.2: an authenticated ('user') module API endpoint is reachable
        // only when the module is enabled for the caller's tenant; a public endpoint
        // stays reachable regardless. The token's tenant is the user's tenant (set
        // by the API auth + RLS middleware), which setUp() enabled the module for.
        $token = (new TokenService())->create($this->userId, 'gate', ['me:read'], null, null)['token'];
        $authJson = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];

        // (a) module enabled (setUp) -> the secure endpoint is reached.
        $this->configRequest(['headers' => $authJson]);
        $this->get('/api/v1/m/zztest_web/secure');
        $this->assertResponseOk();
        $this->assertResponseContains('"auth":"user"');

        // (b) disable the module for this tenant -> the same endpoint is now 404.
        $tenantId = (string)ConnectionManager::get('default')->execute(
            'SELECT tenant_id FROM users WHERE id = :id',
            ['id' => $this->userId],
        )->fetch('assoc')['tenant_id'];
        (new TenantModuleService())->disable($tenantId, self::KEY);
        $this->configRequest(['headers' => $authJson]);
        $this->get('/api/v1/m/zztest_web/secure');
        $this->assertResponseCode(404);

        // (c) the public endpoint is still served while the module is disabled.
        $this->configRequest(['headers' => ['Accept' => 'application/json', 'X-Module-Token' => 'queue-tok-123']]);
        $this->get('/api/v1/m/zztest_web/status');
        $this->assertResponseOk();
        $this->assertResponseContains('"ok":true');
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
