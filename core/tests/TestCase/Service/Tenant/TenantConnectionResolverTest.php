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
 * Test der Mandanten-Verbindungsauflösung (#10/4, DB-pro-Mandant): Pool-Default,
 * fail-closed ohne DSN, eigene Connection bei gesetzter Out-of-Band-DSN.
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
        // Ohne DB-isolierten Mandanten im Kontext: zentral UND daten = geteilte DB.
        $this->assertSame(ConnectionManager::get('default'), Tenancy::central());
        $this->assertSame(ConnectionManager::get('default'), Tenancy::data());
    }

    public function testIsolatedKeyWithUnderscoreRejectedFailClosed(): void
    {
        // Fail-closed gegen Env-Namens-Kollision ('-' und '_' bilden beide auf '_'
        // ab): ein isolierter Key mit '_' muss abgewiesen werden, BEVOR er auf eine
        // fremde TENANT_DB_*-Env (und damit fremde DB) gemappt werden könnte.
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
            (new TenantConnectionResolver())->for($t); // keine TENANT_DB_ZZTEST_ISO -> wirft
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
            // DSN out-of-band (hier auf die Test-DB gezeigt, damit die Connection real ist).
            putenv('TENANT_DB_ZZTEST_ISO2=' . (string)env('DATABASE_TEST_URL'));

            $resolved = (new TenantConnectionResolver())->for($t);
            $this->assertSame('tenant_zztest-iso2', $resolved->configName());
            $resolved->execute('SELECT 1'); // verbindet wirklich
        } finally {
            $conn->rollback();
            ConnectionManager::drop('tenant_zztest-iso2');
            putenv('TENANT_DB_ZZTEST_ISO2');
        }
    }
}
