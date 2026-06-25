<?php
declare(strict_types=1);

namespace App\Service\Consumption;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Per-tenant consumption for the OPERATOR billing view (Inc 7e): the same figures a
 * tenant sees on its own dashboard (Inc 7d), but for EVERY active tenant, so a
 * Betreiber-Admin can issue a bill per tenant.
 *
 * Reuses {@see TenantConsumptionService::summary()} unchanged (Decision 185, one code
 * path): it iterates the active tenants and, per tenant, switches ONLY the request's
 * RLS tenant GUC (`app.current_tenant_id`) — transaction-local, so it is discarded
 * when the request transaction commits — then reads that tenant's summary. The acting
 * user/groups/bypass GUCs are never touched (only the tenant), so restoring the tenant
 * afterwards fully restores the operator context for the rest of the request.
 *
 * Switching the tenant GUC is a read-only OPERATOR capability, not a privilege
 * escalation: the page is operator-gated (see
 * {@see \App\Controller\Admin\OperatorConsumptionController}), and a normal request
 * never re-sets the tenant GUC the middleware pinned to the user's own tenant.
 *
 * Must run inside the per-request transaction (the switch uses transaction-local
 * `set_config`); request paths always are ({@see \App\Middleware\TransactionRlsMiddleware}).
 */
class OperatorConsumptionService
{
    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /**
     * One row per active tenant: its id + name + consumption summary (null when that
     * tenant has no data). The operator's own tenant context is restored before
     * returning, even if a per-tenant read throws.
     *
     * @return list<array{tenant_id:string, name:string, summary:array<string,mixed>|null}>
     */
    public function perTenant(): array
    {
        $conn = $this->conn();
        // The operator's own tenant, captured to restore after the per-tenant reads.
        $opRow = $conn->execute('SELECT core.current_tenant() AS t')->fetch('assoc');
        $operatorTid = $opRow !== false && $opRow['t'] !== null ? (string)$opRow['t'] : '';

        // Read the tenant registry under the operator's own context first; the tenants
        // table is not per-tenant RLS-scoped (as {@see \App\Service\Tenant\TenantIterator}
        // relies on), so this enumerates every active tenant.
        $tenants = $conn->execute(
            'SELECT id, name FROM tenants WHERE active ORDER BY name',
        )->fetchAll('assoc');

        $svc = new TenantConsumptionService();
        $out = [];
        try {
            foreach ($tenants as $t) {
                // Switch ONLY the tenant GUC (transaction-local). summary() then reads
                // this tenant via core.current_tenant(); user/groups/bypass stay the
                // operator's.
                $conn->execute(
                    "SELECT set_config('app.current_tenant_id', :t, true)",
                    ['t' => (string)$t['id']],
                );
                try {
                    $summary = $svc->summary();
                } catch (Throwable) {
                    // A single tenant's read failing must not blank the whole bill.
                    $summary = null;
                }
                $out[] = [
                    'tenant_id' => (string)$t['id'],
                    'name' => (string)$t['name'],
                    'summary' => $summary,
                ];
            }
        } finally {
            // Always restore the operator's tenant context for the rest of the request.
            $conn->execute(
                "SELECT set_config('app.current_tenant_id', :t, true)",
                ['t' => $operatorTid],
            );
        }

        return $out;
    }
}
