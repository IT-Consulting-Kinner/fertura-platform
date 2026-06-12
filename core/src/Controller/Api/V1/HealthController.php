<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Service\Health\HealthService;
use Cake\Http\Response;

/**
 * GET /api/v1/health — Systemzustand (Scope `health:read`).
 */
class HealthController extends ApiController
{
    public function index(): Response
    {
        if ($denied = $this->requireScope('health:read')) {
            return $denied;
        }
        $report = (new HealthService())->report();

        return $this->json([
            'status' => $report['status'],
            'subsystems' => array_map(
                static fn($s) => ['status' => $s['status'] ?? 'unknown'],
                $report['subsystems'],
            ),
        ]);
    }
}
