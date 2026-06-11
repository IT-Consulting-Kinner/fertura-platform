<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Health\HealthService;
use App\Service\Health\WorkerHeartbeat;

/**
 * Admin status dashboard (ch. 20.2.4 / 20.3): operational state at a glance —
 * subsystem health, module lifecycle, registry, outbox/dead-letter, licenses
 * and worker freshness. Accessible to any administrator (no fixed
 * administration area), as it is a purely read-only operations overview.
 */
class HealthController extends AdminController
{
    protected ?string $requiredArea = null;

    public function index(): void
    {
        $report = (new HealthService())->report();
        $heartbeats = WorkerHeartbeat::all();
        $this->set(compact('report', 'heartbeats'));
    }
}
