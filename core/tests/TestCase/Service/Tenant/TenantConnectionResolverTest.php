<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Tenant;

use App\Service\Tenant\Tenancy;
use App\Service\Tenant\TenantConnectionResolver;
use App\Service\Tenant\TenantService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use RuntimeException;
use function Cake\Core\env;

/**
 * Tests tenant connection resolution (#10/4, DB-per-tenant): default pool,
 * fail-closed without a DSN, dedicated connection when an out-of-band DSN is set.
 */
class TenantConnectionResolverTest extends TestCase
{
    public function testNonIsolatedUsesDefaultPool(): void
    {
        $r = new TenantConnectionResolver();
        $this->assertSame(ConnectionManager::get('default'), $r->for(null));
        $this->assertSame(ConnectionManager::get('default'), $r->for(TenantService::DEFAULT_TENANT_ID));
    }

    public function testTenancyFacadeCentralAndDataDefaultToPool(): void
    {
        // Without a DB-isolated tenant in context: central AND data = shared DB.
        $this->assertSame(ConnectionManager::get('default'), Tenancy::central());
        $this->assertSame(ConnectionManager::get('default'), Tenancy::data());
    }

    public function testIsolatedKeyWithUnderscoreRejectedFailClosed(): void
    {
        // Fail-closed against env-name collision (both '-' and '_' map to '_'):
        // an isolated key containing '_' must be rejected BEFORE it could be
        // mapped to a foreign TENANT_DB_* env (and thus a foreign DB).
        $this->expectException(RuntimeException::class);
        (new TenantConnectionResolver())->isolatedConnection('acme_eu');
    }

    public function testIsolatedConnectionUsesCoreSchemaProfile(): void
    {
        putenv('TENANT_DB_ZZTESTISO3=' . (string)env('DATABASE_TEST_URL'));
        try {
            $resolved = (new TenantConnectionResolver())->isolatedConnection('zztestiso3');
            $cfg = ConnectionManager::getConfig('tenant_zztestiso3');
            $this->assertSame('core', $cfg['schema'] ?? null);
            $this->assertContains('SET search_path TO core, public', (array)($cfg['init'] ?? []));
            $resolved->execute('SELECT 1');
        } finally {
            ConnectionManager::drop('tenant_zztestiso3');
            putenv('TENANT_DB_ZZTESTISO3');
        }
    }

    public function testIsolatedWithoutDsnFailsClosed(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->begin();
        try {
            $t = (new TenantService())->create('zztest-iso', 'Iso')['id'];
            $conn->execute('UPDATE tenants SET db_isolated = true WHERE id = :id', ['id' => $t]);

            $this->expectException(RuntimeException::class);
            (new TenantConnectionResolver())->for($t); // no TENANT_DB_ZZTEST_ISO -> throws
        } finally {
            $conn->rollback();
        }
    }

    public function testIsolatedResolvesOwnConnection(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->begin();
        try {
            $t = (new TenantService())->create('zztest-iso2', 'Iso2')['id'];
            $conn->execute('UPDATE tenants SET db_isolated = true WHERE id = :id', ['id' => $t]);
            // Out-of-band DSN (pointed at the test DB here so the connection is real).
            putenv('TENANT_DB_ZZTEST_ISO2=' . (string)env('DATABASE_TEST_URL'));

            $resolved = (new TenantConnectionResolver())->for($t);
            $this->assertSame('tenant_zztest-iso2', $resolved->configName());
            $resolved->execute('SELECT 1'); // actually connects
        } finally {
            $conn->rollback();
            ConnectionManager::drop('tenant_zztest-iso2');
            putenv('TENANT_DB_ZZTEST_ISO2');
        }
    }
}
