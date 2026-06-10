<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Tenant;

use App\Service\Tenant\TenantResolver;
use App\Service\Tenant\TenantService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;

/**
 * Test des Mandantenfähigkeits-Fundaments (Wettbewerbs-Hebel 1/3): Verwaltung,
 * Benutzerzuordnung und der RLS-Helfer `core.current_tenant()` inkl. Prädikat.
 */
class TenantServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute("DELETE FROM users WHERE email LIKE '%@zztenant.local'");
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zztest-%'");
    }

    public function testDefaultTenantExists(): void
    {
        $svc = new TenantService();
        $default = $svc->get(TenantService::DEFAULT_TENANT_ID);
        $this->assertNotNull($default);
        $this->assertSame('default', $default['key']);
    }

    public function testCreateValidatesKey(): void
    {
        $svc = new TenantService();
        $t = $svc->create('zztest-acme', 'ACME GmbH');
        $this->assertSame('zztest-acme', $t['key']);
        $this->assertNotNull($svc->get($t['id']));

        $this->expectException(InvalidArgumentException::class);
        $svc->create('Invalid Key!', 'x');
    }

    public function testNewUserGetsDefaultTenantAndCanBeReassigned(): void
    {
        $conn = ConnectionManager::get('default');
        $svc = new TenantService();

        // INSERT ohne tenant_id -> Default-Mandant (Single-Org bleibt unverändert).
        $userId = (string)$conn->execute(
            "INSERT INTO users (username, email, status) "
            . "VALUES ('zztenant_u', 'u@zztenant.local', 'active') RETURNING id",
        )->fetch('assoc')['id'];
        $this->assertSame(TenantService::DEFAULT_TENANT_ID, $svc->tenantIdForUser($userId));

        // Umhängen auf einen neuen Mandanten.
        $acme = $svc->create('zztest-acme2', 'ACME 2');
        $svc->assignUser($userId, $acme['id']);
        $this->assertSame($acme['id'], $svc->tenantIdForUser($userId));

        $this->expectException(InvalidArgumentException::class);
        $svc->assignUser($userId, '00000000-0000-0000-0000-0000000000ff');
    }

    public function testBrandingForCurrentTenant(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->begin();
        try {
            $t = (new TenantService())->create('zztest-brand', 'Brand Co', 'Brand Co AG', 'https://x/logo.png');
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $t['id']]);

            $b = (new TenantService())->currentBranding();
            $this->assertNotNull($b);
            $this->assertSame('Brand Co AG', $b['brand_name']);
            $this->assertSame('https://x/logo.png', $b['logo_url']);
        } finally {
            $conn->rollback();
        }
    }

    public function testTenantResolverByDomainAndSubdomain(): void
    {
        $svc = new TenantService();
        $t = $svc->create('zztest-acme3', 'ACME 3');
        ConnectionManager::get('default')->execute(
            'UPDATE tenants SET domain = :d WHERE id = :id',
            ['d' => 'acme3.example.test', 'id' => $t['id']],
        );
        $r = new TenantResolver();

        $this->assertSame($t['id'], $r->resolve('acme3.example.test'), 'exakte Domain');
        $this->assertSame($t['id'], $r->resolve('zztest-acme3.portal.test:8443'), 'Subdomain == Schlüssel (+Port)');
        $this->assertNull($r->resolve('unbekannt.test'));

        // Suspendiert -> nicht mehr auflösbar.
        $svc->setActive($t['id'], false);
        $this->assertNull($r->resolve('acme3.example.test'));
    }

    public function testCurrentTenantFunctionAndPredicate(): void
    {
        $conn = ConnectionManager::get('default');
        $tenantA = TenantService::DEFAULT_TENANT_ID;
        $tenantB = '00000000-0000-0000-0000-000000000002';

        // Transaktion-lokaler Kontext -> automatischer Reset beim Rollback (kein Leak).
        $conn->begin();
        try {
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $tenantA]);
            $got = $conn->execute('SELECT core.current_tenant() AS t')->fetch('assoc')['t'];
            $this->assertSame($tenantA, (string)$got);

            // Prädikat `tenant_id = core.current_tenant()` filtert mandantenscharf.
            $rows = $conn->execute(
                'SELECT v.id FROM (VALUES (1, :a::uuid), (2, :b::uuid)) AS v(id, tenant_id) '
                . 'WHERE v.tenant_id = core.current_tenant() ORDER BY v.id',
                ['a' => $tenantA, 'b' => $tenantB],
            )->fetchAll('assoc');
            $this->assertSame([1], array_map(static fn ($r) => (int)$r['id'], $rows), 'nur Mandant-A-Zeile sichtbar');

            // Ohne Kontext -> NULL -> kein Treffer (fail-closed).
            $conn->execute("SELECT set_config('app.current_tenant_id', '', true)");
            $this->assertNull($conn->execute('SELECT core.current_tenant() AS t')->fetch('assoc')['t']);
            $none = $conn->execute(
                'SELECT v.id FROM (VALUES (1, :a::uuid)) AS v(id, tenant_id) '
                . 'WHERE v.tenant_id = core.current_tenant()',
                ['a' => $tenantA],
            )->fetchAll('assoc');
            $this->assertSame([], $none, 'ohne Mandantenkontext keine mandanten-bezogenen Zeilen');
        } finally {
            $conn->rollback();
        }
    }
}
