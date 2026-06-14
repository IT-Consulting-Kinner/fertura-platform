<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Tenant;

use App\Service\Auth\Sso\SsoService;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * sso_providers is tenant-isolated by an APPLICATION filter, not RLS (E171,
 * Decision 185): it is a pre-auth table, so `SsoService::activeProviders` /
 * `listProviders` filter by the current tenant (host-resolved, default-tenant
 * fallback for single-org) rather than relying on a blocking policy.
 *
 * The filter is a plain WHERE clause, so it is provable without a NOBYPASSRLS
 * role: setting the session tenant context and calling the service is enough.
 */
class SsoProviderTenantTest extends TestCase
{
    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $c */
        $c = ConnectionManager::get('default');

        return $c;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->setContext('');
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = $this->conn();
        $conn->execute("DELETE FROM sso_providers WHERE name LIKE 'zzsso_%'");
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zzsso_%'");
    }

    private function makeTenant(string $key): string
    {
        return (string)$this->conn()->execute(
            'INSERT INTO tenants (key, name) VALUES (:k, :n) RETURNING id',
            ['k' => $key, 'n' => 'ZZ ' . $key],
        )->fetch('assoc')['id'];
    }

    private function makeProvider(string $name, string $tenantId): void
    {
        $this->conn()->execute(
            'INSERT INTO sso_providers (type, name, button_label, active, config, tenant_id) '
            . "VALUES ('oidc', :n, 'Login', true, '{}'::jsonb, :t)",
            ['n' => $name, 't' => $tenantId],
        );
    }

    private function setContext(string $tenantId): void
    {
        $this->conn()->execute("SELECT set_config('app.current_tenant_id', :t, false)", ['t' => $tenantId]);
    }

    public function testActiveProvidersAreTenantFiltered(): void
    {
        $a = $this->makeTenant('zzsso_a');
        $b = $this->makeTenant('zzsso_b');
        $this->makeProvider('zzsso_pa', $a);
        $this->makeProvider('zzsso_pb', $b);
        $svc = new SsoService();

        $this->setContext($a);
        $names = array_column($svc->activeProviders(), 'name');
        $this->assertContains('zzsso_pa', $names, 'tenant A sees its own provider');
        $this->assertNotContains('zzsso_pb', $names, 'tenant A must not see tenant B provider');

        $this->setContext($b);
        $names = array_column($svc->listProviders(), 'name');
        $this->assertContains('zzsso_pb', $names, 'tenant B sees its own provider');
        $this->assertNotContains('zzsso_pa', $names, 'tenant B must not see tenant A provider');
    }

    public function testSsoProvidersHasTenantIdButNoBlockingRls(): void
    {
        $conn = $this->conn();
        $rls = $conn->execute(
            'SELECT relrowsecurity FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . "WHERE n.nspname = 'core' AND c.relname = 'sso_providers'",
        )->fetch('assoc');
        // Pre-auth table: tenant_id present, but NO blocking RLS (would break login).
        $this->assertFalse((bool)$rls['relrowsecurity'], 'sso_providers must NOT have blocking RLS (pre-auth)');

        $col = $conn->execute(
            'SELECT 1 AS ok FROM information_schema.columns '
            . "WHERE table_schema = 'core' AND table_name = 'sso_providers' AND column_name = 'tenant_id'",
        )->fetch('assoc');
        $this->assertNotFalse($col, 'sso_providers must have a tenant_id column');
    }
}
