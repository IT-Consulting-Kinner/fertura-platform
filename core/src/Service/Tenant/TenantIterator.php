<?php
declare(strict_types=1);

namespace App\Service\Tenant;

use App\Service\Permission\RlsContext;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * Runs a callback once per ACTIVE tenant with that tenant's RLS context set
 * (system context: no user/groups, no bypass), each in its own transaction
 * (`SET LOCAL` requires a transaction). Core/module background jobs use this so
 * they never mix tenants — the worker-side cornerstone of the consequent,
 * fail-closed multi-tenancy rule (Decision 185, E163).
 *
 * Request paths do not need this (the `TransactionRlsMiddleware` already sets the
 * per-request tenant context). It exists for the context-less paths: schedulers,
 * outbox/queue workers, cron tasks.
 */
class TenantIterator
{
    private RlsContext $rls;

    public function __construct(?RlsContext $rls = null)
    {
        $this->rls = $rls ?? new RlsContext();
    }

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $c */
        $c = ConnectionManager::get('default');

        return $c;
    }

    /** @return list<string> Ids of the active tenants. */
    public function activeTenantIds(): array
    {
        $rows = $this->conn()->execute(
            'SELECT id FROM tenants WHERE active ORDER BY created_at',
        )->fetchAll('assoc');

        return array_values(array_map(static fn(array $r): string => (string)$r['id'], $rows));
    }

    /**
     * Invokes `$fn($tenantId)` once per active tenant, each inside its own
     * transaction with that tenant's RLS context applied (system context). The
     * callback may catch its own errors for per-tenant isolation; an uncaught
     * exception rolls back that tenant's transaction and propagates.
     *
     * @param callable(string): void $fn
     */
    public function forEachActiveTenant(callable $fn): void
    {
        $conn = $this->conn();
        foreach ($this->activeTenantIds() as $tenantId) {
            $conn->transactional(function () use ($conn, $tenantId, $fn): bool {
                $this->rls->applyLocal($conn, null, [], false, $tenantId);
                $fn($tenantId);

                return true;
            });
        }
    }
}
