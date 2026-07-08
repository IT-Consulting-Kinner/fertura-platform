<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Tenant;

use App\Service\Registry\ContractRegistry;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Per-tenant contribution gate (operator/tenant authz §5, Increment 5 Phase 2):
 * {@see ContractRegistry::gateByTenantModules} drops a module's listeners/collectors/
 * providers for a tenant that has the module disabled, while
 *  - failing OPEN when there is no tenant context (system events, CLI, platform jobs),
 *  - always passing core/platform contributions (a module_key not in core.modules),
 *  - and passing EVERYTHING when the caller opts out via $tenantScoped=false.
 *
 * Verified on the listener path (the one the OutboxWorker consumes), which is the
 * same `activeContributions` path collectors/providers use.
 */
class TenantModuleContributionGateTest extends TestCase
{
    private const CONTRACT = 'zzgate.event.test';
    private const MOD = 'zzgate_mod';

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
        $conn = $this->conn();
        // An active module owning a listener contribution.
        $conn->execute(
            'INSERT INTO modules (module_key, name, version, type, core_compatibility, manifest, status) '
            . "VALUES (:k, 'ZZ Gate', '1.0.0', 'extension', '^1.0.0', '{}'::jsonb, 'active') "
            . 'ON CONFLICT (module_key) DO NOTHING',
            ['k' => self::MOD],
        );
        // The event contract + two listeners: one owned by the module (gateable),
        // one owned by 'core' (module_key not in core.modules -> always passes).
        $conn->execute(
            'INSERT INTO contracts (owner_module_key, name, contract_type, version, multi_use, active) '
            . "VALUES ('core', :n, 'event', '1.0.0', true, true) ON CONFLICT (name) DO NOTHING",
            ['n' => self::CONTRACT],
        );
        $cid = (string)$conn->execute(
            'SELECT id FROM contracts WHERE name = :n',
            ['n' => self::CONTRACT],
        )->fetch('assoc')['id'];
        $conn->execute(
            'INSERT INTO contract_registrations (contract_id, module_key, registration_type, implementation_class, active) '
            . "VALUES (:c, :mk, 'listener', 'ZzGateMod\\\\Listener', true), (:c, 'core', 'listener', 'Core\\\\GateListener', true)",
            ['c' => $cid, 'mk' => self::MOD],
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = $this->conn();
        $conn->execute(
            'DELETE FROM contract_registrations WHERE contract_id IN (SELECT id FROM contracts WHERE name = :n)',
            ['n' => self::CONTRACT],
        );
        $conn->execute('DELETE FROM contracts WHERE name = :n', ['n' => self::CONTRACT]);
        // Deleting the module / tenants cascades any tenant_modules grants.
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zzgate_%'");
        $conn->execute('DELETE FROM modules WHERE module_key = :k', ['k' => self::MOD]);
    }

    private function makeTenant(string $key): string
    {
        return (string)$this->conn()->execute(
            'INSERT INTO tenants (key, name) VALUES (:k, :n) RETURNING id',
            ['k' => $key, 'n' => 'ZZ ' . $key],
        )->fetch('assoc')['id'];
    }

    /** Sets the GLOBAL core.tenancy.mode setting (rolled back with the test tx). */
    private function setTenantMode(string $mode): void
    {
        $conn = $this->conn();
        $conn->execute("DELETE FROM settings WHERE namespace = 'core' AND config_key = 'tenancy.mode' AND tenant_id IS NULL");
        $conn->execute(
            "INSERT INTO settings (namespace, config_key, value) VALUES ('core', 'tenancy.mode', to_jsonb(:m::text))",
            ['m' => $mode],
        );
    }

    /** module_keys of the listeners the registry resolves for the test contract. */
    private function listenerModuleKeys(bool $tenantScoped = true): array
    {
        return array_map(
            static fn(array $c): string => $c['module_key'],
            (new ContractRegistry())->listenerContributions(self::CONTRACT, $tenantScoped),
        );
    }

    public function testGatedByTenantEnablement(): void
    {
        $a = $this->makeTenant('zzgate_a');
        $conn = $this->conn();
        $conn->begin();
        try {
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $a]);

            // No grant for the module -> its listener is dropped; core always passes.
            $keys = $this->listenerModuleKeys();
            $this->assertContains('core', $keys);
            $this->assertNotContains(self::MOD, $keys, 'disabled module listener must be gated out');

            // Enable the module for this tenant -> its listener returns.
            $conn->execute(
                'INSERT INTO tenant_modules (tenant_id, module_key, enabled) VALUES (:t, :k, true)',
                ['t' => $a, 'k' => self::MOD],
            );
            $keys = $this->listenerModuleKeys();
            $this->assertContains(self::MOD, $keys);
            $this->assertContains('core', $keys);

            // Disable -> gated out again.
            $conn->execute(
                'UPDATE tenant_modules SET enabled = false WHERE tenant_id = :t AND module_key = :k',
                ['t' => $a, 'k' => self::MOD],
            );
            $this->assertNotContains(self::MOD, $this->listenerModuleKeys());
        } finally {
            $conn->rollback();
        }
    }

    public function testFailsOpenWithoutTenantContext(): void
    {
        $conn = $this->conn();
        $conn->begin();
        try {
            // No tenant context (system event / CLI / platform job) -> nothing filtered.
            $conn->execute("SELECT set_config('app.current_tenant_id', '', true)");
            $keys = $this->listenerModuleKeys();
            $this->assertContains(self::MOD, $keys, 'no tenant context must fail OPEN');
            $this->assertContains('core', $keys);
        } finally {
            $conn->rollback();
        }
    }

    public function testUnGatedReturnsAllRegardlessOfTenant(): void
    {
        $a = $this->makeTenant('zzgate_a');
        $conn = $this->conn();
        $conn->begin();
        try {
            // Tenant set, module NOT enabled, but the caller opts out of gating.
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $a]);
            $keys = $this->listenerModuleKeys(false);
            $this->assertContains(self::MOD, $keys, 'un-gated must include the disabled module');
            $this->assertContains('core', $keys);
        } finally {
            $conn->rollback();
        }
    }

    public function testOperatorTenantOwnsNoModulesInMultiOrgMode(): void
    {
        $operator = '00000000-0000-0000-0000-000000000001';
        $conn = $this->conn();
        $conn->begin();
        try {
            // The module is enabled for the operator/default tenant, acting as it.
            $conn->execute(
                'INSERT INTO tenant_modules (tenant_id, module_key, enabled) VALUES (:t, :k, true) '
                . 'ON CONFLICT (tenant_id, module_key) DO UPDATE SET enabled = true',
                ['t' => $operator, 'k' => self::MOD],
            );
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $operator]);

            // single_org (the default): the operator keeps its dual role -> listener runs.
            $this->assertContains(self::MOD, $this->listenerModuleKeys(), 'single_org: operator uses modules');

            // multi_org: the operator tenant runs operator functions only -> EVERY module
            // contribution is dropped (operator-tenant design §5b, Option B); core passes.
            $this->setTenantMode('multi_org');
            $keys = $this->listenerModuleKeys();
            $this->assertNotContains(self::MOD, $keys, 'multi_org: operator tenant owns no modules');
            $this->assertContains('core', $keys, 'core/platform contributions still pass');
        } finally {
            $conn->rollback();
        }
    }

    public function testCrossTenantIsolation(): void
    {
        $a = $this->makeTenant('zzgate_a');
        $b = $this->makeTenant('zzgate_b');
        $conn = $this->conn();
        $conn->begin();
        try {
            // Enabled for A only.
            $conn->execute(
                'INSERT INTO tenant_modules (tenant_id, module_key, enabled) VALUES (:t, :k, true)',
                ['t' => $a, 'k' => self::MOD],
            );
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $a]);
            $this->assertContains(self::MOD, $this->listenerModuleKeys(), 'tenant A enabled -> listener runs');

            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $b]);
            $this->assertNotContains(self::MOD, $this->listenerModuleKeys(), 'tenant B not enabled -> listener gated');
        } finally {
            $conn->rollback();
        }
    }
}
