<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Admin;

use App\Service\Admin\ListPrefsService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Per-user list preferences (Paket 2): load/save round trip, empty-filter
 * pruning, and the upsert keyed on (user_id, list_key).
 */
class ListPrefsServiceTest extends TestCase
{
    private string $userId = '';

    private const TENANT = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $conn = ConnectionManager::get('default');
        // The service runs in-request where TransactionRlsMiddleware sets the
        // tenant context; user_list_prefs.tenant_id defaults to it. Mimic that
        // so the NOT NULL default resolves in this context-less unit test.
        $conn->execute("SELECT set_config('app.current_tenant_id', :t, false)", ['t' => self::TENANT]);
        $this->userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zzlp_' . bin2hex(random_bytes(3)), 'e' => bin2hex(random_bytes(3)) . '@zzlistprefs.local'],
        )->fetch('assoc')['id'];
    }

    protected function tearDown(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute('DELETE FROM user_list_prefs WHERE user_id = :u', ['u' => $this->userId]);
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zzlistprefs.local'");
        $conn->execute("SELECT set_config('app.current_tenant_id', '', false)");
        parent::tearDown();
    }

    public function testLoadReturnsEmptyBagWhenNothingStored(): void
    {
        $out = (new ListPrefsService())->load($this->userId, 'audit');
        $this->assertNull($out['per_page']);
        $this->assertSame([], $out['filters']);
    }

    public function testSaveThenLoadRoundTripAndPrunesEmptyFilters(): void
    {
        $service = new ListPrefsService();
        $service->save($this->userId, 'audit', 100, ['action' => 'user', 'entity_type' => '', 'module_key' => 'kb']);

        $out = $service->load($this->userId, 'audit');
        $this->assertSame(100, $out['per_page']);
        // Empty filter values are dropped; only non-empty ones round-trip.
        $this->assertSame(['action' => 'user', 'module_key' => 'kb'], $out['filters']);
    }

    public function testSaveUpsertsSameRowPerList(): void
    {
        $service = new ListPrefsService();
        $service->save($this->userId, 'audit', 50, ['action' => 'a']);
        $service->save($this->userId, 'audit', 25, ['action' => 'b']); // overwrite

        $count = (int)ConnectionManager::get('default')->execute(
            "SELECT count(*) AS c FROM user_list_prefs WHERE user_id = :u AND list_key = 'audit'",
            ['u' => $this->userId],
        )->fetch('assoc')['c'];
        $this->assertSame(1, $count, 'one row per (user, list)');

        $out = $service->load($this->userId, 'audit');
        $this->assertSame(25, $out['per_page']);
        $this->assertSame(['action' => 'b'], $out['filters']);

        // A different list_key is a separate row.
        $service->save($this->userId, 'tenants', 200, []);
        $this->assertSame(200, $service->load($this->userId, 'tenants')['per_page']);
        $this->assertSame(25, $service->load($this->userId, 'audit')['per_page']); // unaffected
    }
}
