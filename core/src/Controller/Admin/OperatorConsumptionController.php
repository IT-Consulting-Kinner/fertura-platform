<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Consumption\OperatorConsumptionService;

/**
 * Operator billing view (Inc 7e): a Betreiber-Admin sees per-tenant consumption
 * (enabled functions + storage + DB footprint) across ALL active tenants, to bill
 * each one.
 *
 * Operator-scoped — it lives under the operator `core_config` area (next to tenant
 * management), so the operator gate in {@see AdminController} applies and a tenant
 * admin can never reach it. It reuses the very per-tenant summary the tenant
 * dashboard (Inc 7d) shows, so the operator's figure and the tenant's own always
 * agree.
 */
class OperatorConsumptionController extends AdminController
{
    protected ?string $requiredArea = 'core_config';

    public function index(): void
    {
        $this->set('tenants', (new OperatorConsumptionService())->perTenant());
    }
}
