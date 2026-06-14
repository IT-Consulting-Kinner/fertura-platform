<?php
declare(strict_types=1);

namespace App\Service\Tenant;

use Cake\Database\Connection;
use RuntimeException;

/**
 * Sets the tenant RLS context for a console command from a `--tenant` option.
 *
 * Console commands run outside the request lifecycle, so the
 * `TransactionRlsMiddleware` never sets `app.current_tenant_id` for them. Under
 * the consequent multi-tenancy rule (Decision 185) any command that reads or
 * writes tenant-scoped tables must establish that context itself — otherwise,
 * against the production NOBYPASSRLS app role, reads return nothing and writes
 * are rejected by the fail-closed policy.
 *
 * The option accepts a tenant key or UUID; empty / absent resolves to the default
 * tenant (single-org). The context is set session-wide (not transaction-local) so
 * it holds for the whole command run.
 */
class CliTenantContext
{
    /** Stable id of the default tenant (single-org), mirrors `CoreTenancy`. */
    public const DEFAULT_TENANT_ID = '00000000-0000-0000-0000-000000000001';

    /**
     * Resolves the tenant and applies it as the session context. Returns the
     * resolved tenant id.
     *
     * @throws \RuntimeException if an explicit value matches no tenant.
     */
    public static function apply(Connection $conn, ?string $tenantOption): string
    {
        $tenantId = self::resolve($conn, $tenantOption);
        $conn->execute("SELECT set_config('app.current_tenant_id', :t, false)", ['t' => $tenantId]);

        return $tenantId;
    }

    /** @throws \RuntimeException if an explicit value matches no tenant. */
    private static function resolve(Connection $conn, ?string $tenantOption): string
    {
        $value = trim((string)($tenantOption ?? ''));
        if ($value === '') {
            return self::DEFAULT_TENANT_ID;
        }

        $row = $conn->execute(
            'SELECT id FROM tenants WHERE id::text = :t OR key = :t',
            ['t' => $value],
        )->fetch('assoc');
        if ($row === false) {
            throw new RuntimeException("Unbekannter Mandant: $value");
        }

        return (string)$row['id'];
    }
}
