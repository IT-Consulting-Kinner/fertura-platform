<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Health\HealthService;
use App\Service\Health\WorkerHeartbeat;

/**
 * Admin-Statusfläche (Kap. 20.2.4 / 20.3): Betriebszustand auf einen Blick —
 * Subsystem-Health, Modul-Lifecycle, Registry, Outbox/Dead-Letter, Lizenzen
 * und Worker-Aktualität. Für jeden Administrator zugänglich (kein fester
 * Administrationsbereich), da rein lesende Betriebsübersicht.
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
