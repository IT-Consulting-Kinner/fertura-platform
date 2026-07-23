<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use App\Test\TestCase\AdminAreaSeedTrait;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Per-user list preferences on the audit list (Paket 2): a filter/page-size
 * change carrying the `_lp` marker is persisted, and a later fresh visit (no
 * query) reapplies the stored state.
 */
class AuditListPrefsTest extends TestCase
{
    use IntegrationTestTrait;
    use AdminAreaSeedTrait;

    private string $adminId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "INSERT INTO admin_areas (area_key, label, sort_order) VALUES ('user_group_admin', 'Users', 10) "
            . 'ON CONFLICT (area_key) DO NOTHING',
        );
        $this->adminId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zzauditlp_' . bin2hex(random_bytes(3)), 'e' => bin2hex(random_bytes(3)) . '@zzauditlp.local'],
        )->fetch('assoc')['id'];
        // The audit page is for any ADMINISTRATOR (holds some area); grant one.
        $this->grantAdminAreas($this->adminId, 'user_group_admin');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        if ($this->adminId !== '') {
            $conn->execute('DELETE FROM user_list_prefs WHERE user_id = :u', ['u' => $this->adminId]);
        }
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzauditlp.local'");
    }

    private function login(): void
    {
        $this->session(['Auth' => ['id' => $this->adminId, 'username' => 'zzauditlp', 'email' => 'a@zzauditlp.local']]);
    }

    public function testFilterSubmitPersistsAndFreshVisitReapplies(): void
    {
        $this->login();

        // Active state change (the filter form posts _lp=1): persist page size +
        // the action filter.
        $this->get('/admin/audit?_lp=1&per_page=25&action=user.&entity_type=&module_key=');
        $this->assertResponseOk();

        $stored = ConnectionManager::get('default')->execute(
            "SELECT prefs FROM user_list_prefs WHERE user_id = :u AND list_key = 'audit'",
            ['u' => $this->adminId],
        )->fetch('assoc');
        $this->assertNotFalse($stored, 'preference row was written');
        $prefs = json_decode((string)$stored['prefs'], true);
        $this->assertSame(25, $prefs['per_page']);
        $this->assertSame('user.', $prefs['filters']['action']);
        $this->assertArrayNotHasKey('entity_type', $prefs['filters']); // empty pruned

        // Fresh visit (no query): the stored action filter is reapplied to the
        // form field.
        $this->get('/admin/audit');
        $this->assertResponseOk();
        $this->assertResponseContains('value="user."'); // the action input carries the stored value
    }

    public function testResetMarkerClearsStoredFilters(): void
    {
        $this->login();
        $this->get('/admin/audit?_lp=1&per_page=100&action=something');
        // Reset link posts _lp=1 with no filter values -> persists the cleared state.
        $this->get('/admin/audit?_lp=1');

        $prefs = json_decode((string)ConnectionManager::get('default')->execute(
            "SELECT prefs FROM user_list_prefs WHERE user_id = :u AND list_key = 'audit'",
            ['u' => $this->adminId],
        )->fetch('assoc')['prefs'], true);
        $this->assertSame([], $prefs['filters'], 'filters cleared');
    }
}
