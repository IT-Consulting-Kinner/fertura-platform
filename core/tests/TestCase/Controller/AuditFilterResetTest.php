<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Audit\AuditLogger;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Regression for the admin audit "Aktion" filter. The field must be a clearable
 * free-text input (ILIKE "contains"), NOT a magic <select> built from the plural
 * view var $actions. With a prior filter set, an explicit empty action (?action=)
 * and the Reset link (no query) must both show ALL entries and empty the input.
 */
class AuditFilterResetTest extends TestCase
{
    use IntegrationTestTrait;

    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "INSERT INTO admin_areas (area_key, label, sort_order) VALUES ('core_config', 'Core', 60) "
            . 'ON CONFLICT (area_key) DO NOTHING',
        );
        $this->adminId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_audflt_' . bin2hex(random_bytes(3)), 'e' => 'audflt_' . bin2hex(random_bytes(3)) . '@zzaudit.local'],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) VALUES (:u, :a)',
            ['u' => $this->adminId, 'a' => 'core_config'],
        );
        // Two distinct actions so a filter on one hides the other in the table.
        (new AuditLogger())->log('zztest.alpha', 'zztest_e', '019eb000-0000-7000-8000-00000000000a');
        (new AuditLogger())->log('zztest.beta', 'zztest_e', '019eb000-0000-7000-8000-00000000000b');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        // audit_log is append-only (trigger) -> bypass within a transaction.
        $conn->begin();
        $conn->execute("SET LOCAL app.allow_audit_mutation = 'on'");
        $conn->execute("DELETE FROM audit_log WHERE action LIKE 'zztest.%'");
        $conn->commit();
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzaudit.local'");
    }

    private function login(): void
    {
        $this->session(['Auth' => ['id' => $this->adminId, 'username' => 'zztest_audflt', 'email' => 'a@zzaudit.local']]);
    }

    /** The <code>…</code> cell is unique to the entries TABLE (datalist uses <option>). */
    private function assertRowListed(string $action): void
    {
        $this->assertResponseContains('<code class="small">' . $action . '</code>');
    }

    private function assertRowNotListed(string $action): void
    {
        $this->assertResponseNotContains('<code class="small">' . $action . '</code>');
    }

    /** The current value of the rendered `action` input element (empty string if none). */
    private function actionInputValue(): string
    {
        $body = (string)$this->_response->getBody();
        $this->assertMatchesRegularExpression('/<input[^>]*name="action"[^>]*>/', $body, 'action must be an <input>, not a <select>');
        preg_match('/<input[^>]*name="action"[^>]*>/', $body, $m);
        preg_match('/\bvalue="([^"]*)"/', $m[0], $v);

        return $v[1] ?? '';
    }

    public function testActionFieldIsTextInputNotSelect(): void
    {
        $this->login();
        $this->get('/admin/audit');
        $this->assertResponseOk();
        // The regression: the field must not be a magic <select name="action">.
        $this->assertResponseNotContains('<select name="action"');
        $this->assertMatchesRegularExpression('/<input[^>]*name="action"[^>]*>/', (string)$this->_response->getBody());
    }

    public function testFilterNarrowsThenResetClears(): void
    {
        $this->login();

        // 1) Filter by action -> only the matching row is listed; input shows it.
        $this->get('/admin/audit?action=zztest.alpha');
        $this->assertResponseOk();
        $this->assertRowListed('zztest.alpha');
        $this->assertRowNotListed('zztest.beta');
        $this->assertSame('zztest.alpha', $this->actionInputValue());

        // 2) Reset link (?_lp=1, no filter values) -> the persisted filter is
        // cleared (Paket 2): BOTH rows listed, input empty. A bare visit would
        // now REAPPLY the stored filter, so reset must carry the marker.
        $this->get('/admin/audit?_lp=1');
        $this->assertResponseOk();
        $this->assertRowListed('zztest.alpha');
        $this->assertRowListed('zztest.beta');
        $this->assertSame('', $this->actionInputValue());
    }

    public function testBareVisitReappliesStoredFilter(): void
    {
        // Paket 2: after filtering, navigating away and back (a bare visit with
        // no query) reapplies the stored filter for this user.
        $this->login();
        $this->get('/admin/audit?action=zztest.alpha');
        $this->assertRowNotListed('zztest.beta');

        $this->get('/admin/audit'); // bare -> stored filter reapplied
        $this->assertResponseOk();
        $this->assertRowListed('zztest.alpha');
        $this->assertRowNotListed('zztest.beta');
        $this->assertSame('zztest.alpha', $this->actionInputValue());
    }

    public function testExplicitEmptyActionClearsFilter(): void
    {
        $this->login();

        $this->get('/admin/audit?action=zztest.alpha');
        $this->assertRowNotListed('zztest.beta');

        // Explicit empty action query -> filter cleared, input empty.
        $this->get('/admin/audit?action=&entity_type=&module_key=');
        $this->assertResponseOk();
        $this->assertRowListed('zztest.alpha');
        $this->assertRowListed('zztest.beta');
        $this->assertSame('', $this->actionInputValue());
    }
}
