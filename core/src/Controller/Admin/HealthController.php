<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Health\HealthService;
use App\Service\Health\WorkerHeartbeat;

/**
 * Admin status dashboard (ch. 20.2.4 / 20.3): operational state at a glance —
 * subsystem health, module lifecycle, registry, outbox/dead-letter, licenses
 * and worker freshness. This is PLATFORM-WIDE operator data (worker heartbeats,
 * DB role, storage paths, backup/license/module status across all tenants and not
 * tenant-scoped), so it is OPERATOR-ONLY — a tenant admin must not see it. No fixed
 * area (any operator admin), but the operator-tenant gate is enforced.
 */
class HealthController extends AdminController
{
    protected ?string $requiredArea = null;

    protected bool $requiresOperator = true;

    public function index(): void
    {
        $report = (new HealthService())->report();
        $heartbeats = WorkerHeartbeat::all();
        $this->set(compact('report', 'heartbeats'));
    }
}
