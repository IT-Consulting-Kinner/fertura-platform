<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Tenant;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Cross-tenant-leak test for the outbound-webhook tables (E167, Decision 185):
 * under a NOBYPASSRLS role, tenant B never sees tenant A's webhook subscriptions,
 * and a missing context sees nothing (fail-closed). Plus a structural check that
 * both webhook tables carry a `tenant_id` column + RLS.
 *
 * Mirrors {@see AutomationTenantRlsTest}: RLS only bites for a non-owner
 * NOBYPASSRLS role, so the visibility assertions switch role via `SET LOCAL ROLE`.
 */
class WebhookTenantRlsTest extends TestCase
{
    private const ROLE = 'fertura_rls_whtest';
    private const TABLES = [
        'webhook_subscriptions',
        'webhook_deliveries',
    ];

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
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = $this->conn();
        $conn->execute("DELETE FROM webhook_subscriptions WHERE name LIKE 'zzwh_%'");
        $conn->execute("DELETE FROM tenants WHERE key LIKE 'zzwh_%'");
    }

    private function makeTenant(string $key): string
    {
        return (string)$this->conn()->execute(
            'INSERT INTO tenants (key, name) VALUES (:k, :n) RETURNING id',
            ['k' => $key, 'n' => 'ZZ ' . $key],
        )->fetch('assoc')['id'];
    }

    private function makeSubscription(string $name, string $tenantId): void
    {
        // Inserted as superuser (no tenant context) -> set tenant_id explicitly.
        $this->conn()->execute(
            'INSERT INTO webhook_subscriptions (name, url, event_filter, active, tenant_id) '
            . "VALUES (:n, 'https://example.test/hook', '*', true, :t)",
            ['n' => $name, 't' => $tenantId],
        );
    }

    private function ensureRole(): void
    {
        $conn = $this->conn();
        $conn->execute(
            "DO $$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='" . self::ROLE . "') THEN "
            . 'CREATE ROLE ' . self::ROLE . ' NOLOGIN NOBYPASSRLS; END IF; END $$;',
        );
        $conn->execute('GRANT USAGE ON SCHEMA core TO ' . self::ROLE);
        $conn->execute('GRANT SELECT ON core.webhook_subscriptions TO ' . self::ROLE);
    }

    private function countAsRole(string $tenantId): int
    {
        $conn = $this->conn();
        $conn->begin();
        try {
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $tenantId]);
            $conn->execute("SELECT set_config('app.bypass_rls', 'false', true)");
            $conn->execute('SET LOCAL ROLE ' . self::ROLE);

            return (int)$conn->execute(
                "SELECT count(*) c FROM core.webhook_subscriptions WHERE name LIKE 'zzwh_%'",
            )->fetch('assoc')['c'];
        } finally {
            $conn->rollback();
        }
    }

    public function testWebhookSubscriptionsAreTenantScopedUnderRls(): void
    {
        $a = $this->makeTenant('zzwh_a');
        $b = $this->makeTenant('zzwh_b');
        $this->makeSubscription('zzwh_sub_a', $a);
        $this->makeSubscription('zzwh_sub_b', $b);
        $this->ensureRole();

        $this->assertSame(1, $this->countAsRole($a), 'tenant A sees only its own subscription');
        $this->assertSame(1, $this->countAsRole($b), 'tenant B sees only its own subscription');
        $this->assertSame(0, $this->countAsRole(''), 'no tenant context -> fail-closed, sees nothing');
    }

    public function testBothWebhookTablesHaveTenantIdColumnAndRls(): void
    {
        $conn = $this->conn();
        foreach (self::TABLES as $t) {
            $rls = $conn->execute(
                'SELECT relrowsecurity FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
                . "WHERE n.nspname = 'core' AND c.relname = :t",
                ['t' => $t],
            )->fetch('assoc');
            $this->assertTrue((bool)$rls['relrowsecurity'], "$t must have RLS enabled");

            $col = $conn->execute(
                'SELECT 1 AS ok FROM information_schema.columns '
                . "WHERE table_schema = 'core' AND table_name = :t AND column_name = 'tenant_id'",
                ['t' => $t],
            )->fetch('assoc');
            $this->assertNotFalse($col, "$t must have a tenant_id column");
        }
    }
}
