<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Dashboard;

use App\Service\Dashboard\DashboardTileCollector;
use App\Service\Dashboard\DashboardTileProviderInterface;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Fixture provider registered on the collector; returns tiles plus hostile
 * entries the coercer must drop.
 */
class ZzDashTileProvider implements DashboardTileProviderInterface
{
    public function dashboardTiles(array $context): array
    {
        return [
            ['label' => 'Offene Tickets', 'value' => 7, 'url' => '/m/zzdash/queue', 'variant' => 'warning'],
            ['value' => 3], // no label -> dropped entirely
            ['label' => 'Fremd', 'value' => 1, 'url' => 'https://evil.test/x'], // survives; off-site url dropped
            ['label' => 'Bad variant', 'value' => 1, 'variant' => 'evil"><script>'], // survives; variant dropped
        ];
    }
}

class DashboardTileCollectorTest extends TestCase
{
    private string $contractId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $conn = ConnectionManager::get('default');
        // The core contract is seeded by a migration, but the test migrator
        // truncates seed data -> ensure it exists.
        $conn->execute(
            'INSERT INTO contracts (owner_module_key, name, contract_type, version, multi_use, active) '
            . "VALUES ('core', 'core.collector.dashboard_tiles', 'collector', '1.0.0', true, true) ON CONFLICT (name) DO NOTHING",
        );
        $this->contractId = (string)$conn->execute(
            "SELECT id FROM contracts WHERE name = 'core.collector.dashboard_tiles'",
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO contract_registrations (contract_id, module_key, registration_type, implementation_class, active) '
            . "VALUES (:c, 'zzdash', 'collector_contribution', :cls, true)",
            ['c' => $this->contractId, 'cls' => ZzDashTileProvider::class],
        );
        // A display name for the accordion group heading.
        $conn->execute(
            'INSERT INTO modules (module_key, name, version, type, edition, core_compatibility, manifest, status) '
            . "VALUES ('zzdash', 'ZZ Dashboard', '1.0.0', 'main', 'free', '>=1.0.0 <2.0.0', "
            . "CAST('{}' AS jsonb), 'active') ON CONFLICT (module_key) DO NOTHING",
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute("DELETE FROM contract_registrations WHERE module_key = 'zzdash'");
        $conn->execute("DELETE FROM tenant_modules WHERE module_key = 'zzdash'");
        $conn->execute("DELETE FROM modules WHERE module_key = 'zzdash'");
        $conn->execute("SELECT set_config('app.current_tenant_id', '', false)");
    }

    public function testCollectCoercesTilesAndGroupsByModule(): void
    {
        $groups = (new DashboardTileCollector())->collect(['is_operator' => true]);

        $zz = null;
        foreach ($groups as $g) {
            if ($g['key'] === 'zzdash') {
                $zz = $g;
            }
        }
        $this->assertNotNull($zz, 'a group for the contributing module exists');
        $this->assertSame('ZZ Dashboard', $zz['name']); // module display name

        // Three tiles survive (the label-less one is dropped entirely).
        $this->assertCount(3, $zz['tiles']);
        $first = $zz['tiles'][0];
        $this->assertSame('Offene Tickets', $first['label']);
        $this->assertSame('7', $first['value']); // int coerced to string
        $this->assertSame('/m/zzdash/queue', $first['url']);
        $this->assertSame('warning', $first['variant']);

        // The off-site tile survives but its url is dropped; the bad-variant tile
        // survives but its variant is dropped.
        $offsite = $zz['tiles'][1];
        $this->assertSame('Fremd', $offsite['label']);
        $this->assertArrayNotHasKey('url', $offsite);
        $badVariant = $zz['tiles'][2];
        $this->assertArrayNotHasKey('variant', $badVariant);

        // Hostile VALUES never survive (labels may, but the off-site host and the
        // script payload must not).
        $json = (string)json_encode($zz);
        $this->assertStringNotContainsString('evil.test', $json);
        $this->assertStringNotContainsString('<script>', $json);
    }

    public function testGroupHeadingUsesLocalizedNavGroupLabel(): void
    {
        // Positive path of moduleLabels(): a module with a web_route carrying a
        // nav_group gets that resolved label as the heading (like the menu),
        // NOT the manifest name. Point zzdash's source_path at the zztest_web
        // fixture (its manifest has an admin route with nav_group
        // 'zztest.nav.group'); with no zzdash .po loaded, __d returns the key
        // verbatim — which is exactly what the menu would show too, and proves
        // the override branch ran (heading != manifest name 'ZZ Dashboard').
        ConnectionManager::get('default')->execute(
            'UPDATE modules SET source_path = :p WHERE module_key = :k',
            ['p' => ROOT . '/tests/Fixture/zztest_web', 'k' => 'zzdash'],
        );

        $groups = (new DashboardTileCollector())->collect(['is_operator' => true]);
        $zz = null;
        foreach ($groups as $g) {
            if ($g['key'] === 'zzdash') {
                $zz = $g;
            }
        }
        $this->assertNotNull($zz);
        $this->assertSame('zztest.nav.group', $zz['name'], 'nav_group override ran, not the manifest name');
    }

    public function testDisabledModuleContributesNoTilesUnderTenantContext(): void
    {
        // Tenant gate (operator/tenant authz §5): with a tenant context where the
        // installed module is NOT enabled, its collector contribution is dropped —
        // no group. (The prior test relies on the tenant-less fail-open path.)
        $conn = ConnectionManager::get('default');
        $conn->execute(
            "SELECT set_config('app.current_tenant_id', '00000000-0000-0000-0000-000000000001', false)",
        );

        $groups = (new DashboardTileCollector())->collect(['is_operator' => true]);
        $keys = array_column($groups, 'key');
        $this->assertNotContains('zzdash', $keys, 'a not-enabled module contributes no dashboard group');
    }

    public function testTileCountAndLabelAreCapped(): void
    {
        $tiles = ZzFloodTileProvider::coerceThrough();
        // MAX_TILES_PER_MODULE = 12; the flood provider returns 50.
        $this->assertLessThanOrEqual(12, count($tiles));
        // The oversized label is truncated (MAX_LABEL_LEN = 120).
        $this->assertLessThanOrEqual(120, mb_strlen($tiles[0]['label']));
    }
}

/** Provider returning far more tiles than the cap, with an oversized label. */
class ZzFloodTileProvider implements DashboardTileProviderInterface
{
    public function dashboardTiles(array $context): array
    {
        $tiles = [['label' => str_repeat('X', 500), 'value' => 1]];
        for ($i = 0; $i < 50; $i++) {
            $tiles[] = ['label' => 'Tile ' . $i, 'value' => $i];
        }

        return $tiles;
    }

    /** Registers this provider, runs the collector, returns the zzflood tiles. */
    public static function coerceThrough(): array
    {
        $conn = ConnectionManager::get('default');
        $cid = (string)$conn->execute(
            "SELECT id FROM contracts WHERE name = 'core.collector.dashboard_tiles'",
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO contract_registrations (contract_id, module_key, registration_type, implementation_class, active) '
            . "VALUES (:c, 'zzflood', 'collector_contribution', :cls, true)",
            ['c' => $cid, 'cls' => self::class],
        );
        $conn->execute(
            'INSERT INTO modules (module_key, name, version, type, edition, core_compatibility, manifest, status) '
            . "VALUES ('zzflood', 'ZZ Flood', '1.0.0', 'main', 'free', '>=1.0.0 <2.0.0', CAST('{}' AS jsonb), 'active') "
            . 'ON CONFLICT (module_key) DO NOTHING',
        );
        try {
            foreach ((new DashboardTileCollector())->collect(['is_operator' => true]) as $g) {
                if ($g['key'] === 'zzflood') {
                    return $g['tiles'];
                }
            }

            return [];
        } finally {
            $conn->execute("DELETE FROM contract_registrations WHERE module_key = 'zzflood'");
            $conn->execute("DELETE FROM modules WHERE module_key = 'zzflood'");
        }
    }
}
