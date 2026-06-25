<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Consumption\TenantConsumptionService;

/**
 * Tenant admin consumption dashboard (Inc 7d): shows the current tenant what it
 * consumes — enabled functions (modules) + storage (backup archives, per-tenant file
 * store, approximate DB footprint).
 *
 * Tenant-scoped area (in {@see AdminController::TENANT_AREAS}, no operator gate);
 * every figure is RLS-scoped to `core.current_tenant()`, so a tenant only ever sees
 * its own usage. The operator's per-tenant billing view (Inc 7e) reuses the same
 * {@see TenantConsumptionService} once per tenant under each tenant's context.
 */
class ConsumptionController extends AdminController
{
    protected ?string $requiredArea = 'consumption';

    public function index(): void
    {
        // null when there is no tenant context (fail-closed); the template renders an
        // empty state rather than platform-wide totals.
        $this->set('summary', (new TenantConsumptionService())->summary());
    }
}
