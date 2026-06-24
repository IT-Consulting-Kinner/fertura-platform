<?php
declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Schedule\ScheduledTaskInterface;
use App\Service\Settings\SettingsManager;
use App\Service\Tenant\TenantIterator;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Runs the tenants' scheduled backup PLANS (Inc 7c). Ticked by the core worker via
 * {@see \App\Service\Schedule\ScheduledTaskRunner}. Iterates the active tenants and,
 * for each, sets that tenant's RLS context (NOT a bypass — so every query is scoped
 * to the tenant; mirrors the per-tenant worker pattern and avoids the F13 no-op)
 * then runs that tenant's due plans. One tenant's failure is isolated from the rest.
 *
 * Distinct from the operator-only {@see BackupScheduledTask} (full-DB DR backup):
 * this only ever creates each tenant's OWN scoped data backups.
 */
class TenantBackupScheduledTask implements ScheduledTaskInterface
{
    public function key(): string
    {
        return 'core.tenant_backup.auto';
    }

    public function intervalSeconds(): int
    {
        $minutes = (int)(new SettingsManager())->get('core', 'tenant_backup.scheduler.interval_minutes', 15);

        return max(60, $minutes * 60);
    }

    public function run(): void
    {
        // Deployment kill-switch (default on); individual plans are opt-in per tenant.
        if (!(bool)(new SettingsManager())->get('core', 'tenant_backup.scheduler.enabled', true)) {
            return;
        }
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        foreach ((new TenantIterator())->activeTenantIds() as $tenantId) {
            // Per-tenant RLS context (no bypass) so create()/pruneForPlan only touch
            // this tenant; reset afterwards. Session-scoped because each plan's
            // backup is a slow archive build that must not hold a DB transaction open.
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, false)", ['t' => $tenantId]);
            $conn->execute("SELECT set_config('app.bypass_rls', 'false', false)");
            try {
                (new TenantBackupPlanService())->runDuePlans();
            } catch (Throwable) {
                // Isolate one tenant's failure so the others still run.
            } finally {
                $conn->execute("SELECT set_config('app.current_tenant_id', '', false)");
            }
        }
    }
}
