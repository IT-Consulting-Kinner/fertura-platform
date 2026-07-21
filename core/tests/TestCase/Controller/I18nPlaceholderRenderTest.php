<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Service\Identity\PasswordResetService;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\I18n;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Regression guard for the i18n sprintf-placeholder bug: a parametrized
 * translation must render its ARGUMENT, never the raw `%d`/`%s` token.
 *
 * The `/account` password hint (`account.password_hint` = "At least %d
 * characters.", rendered by {@see \App\Controller\AccountController::index()} via
 * `__('account.password_hint', $minPassword)`) is the canonical case: it showed
 * the raw "%d" in the browser while bare unit tests stayed green, because the web
 * and the test environment resolved different message formatters (ICU vs.
 * sprintf).
 *
 * Two complementary assertions:
 *  - {@see self::testNumericPlaceholderIsSubstitutedInRenderedAccountTemplate()}
 *    renders the real route + template + middleware and asserts the argument is
 *    interpolated (the symptom, at the rendering layer).
 *  - {@see self::testGlobalMessageFormatterIsSprintf()} pins the root cause: the
 *    global default formatter. This is the assertion that goes RED if the fix in
 *    config/bootstrap.php is reverted — the built-in fallback loader (the path
 *    the test env, and a cache-primed web request, both take) would fall back to
 *    ICU and render `%d` raw again.
 */
class I18nPlaceholderRenderTest extends TestCase
{
    use IntegrationTestTrait;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        // Any active user is enough: /account is reachable by every authenticated
        // user, with no admin-area gate ({@see \App\Controller\AccountController}).
        $this->userId = (string)ConnectionManager::get('default')->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_i18n_' . bin2hex(random_bytes(3)), 'e' => 'i18n_' . bin2hex(random_bytes(3)) . '@zzi18n.local'],
        )->fetch('assoc')['id'];
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        ConnectionManager::get('default')->execute("DELETE FROM users WHERE email LIKE '%@zzi18n.local'");
    }

    /**
     * The numeric `%d` argument of the password hint must be interpolated in the
     * rendered `/account` template — and the raw sprintf token must not survive
     * to the browser.
     */
    public function testNumericPlaceholderIsSubstitutedInRenderedAccountTemplate(): void
    {
        // Pin the locale so the assertion is deterministic (en_US is enabled by
        // default); the English catalog msgstr is "At least %d characters.".
        $this->session([
            'Auth' => ['id' => $this->userId, 'username' => 'zztest_i18n', 'email' => 'i@zzi18n.local'],
            'locale' => 'en_US',
        ]);

        // Mirror how the controller derives the value the template renders.
        $min = (new PasswordResetService())->minPasswordLength();

        $this->get('/account');

        $this->assertResponseOk();
        $this->assertResponseContains(
            "At least {$min} characters.",
            'The %d placeholder must be substituted with the configured minimum length.',
        );
        $this->assertResponseNotContains(
            'At least %d characters.',
            'The raw sprintf token %d must not survive to the rendered page.',
        );
    }

    /**
     * Root-cause guard: the core catalogs use sprintf placeholders, so the global
     * default message formatter must be `sprintf`. If this reverts to CakePHP's
     * default (`default` = ICU), every `%s`/`%d` rendered through the built-in
     * fallback loader turns raw again — the original bug.
     */
    public function testGlobalMessageFormatterIsSprintf(): void
    {
        $this->assertSame('sprintf', I18n::getDefaultFormatter());
    }
}
