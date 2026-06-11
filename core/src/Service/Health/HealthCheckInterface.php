<?php
declare(strict_types=1);

namespace App\Service\Health;

/**
 * Implementation contract for module health contributions (ch. 20.2.2).
 *
 * Modules register implementations as collector contributions against the
 * core contract `core.collector.health`. The core aggregates the contributions
 * into the health endpoint. With no registered contributions the result stays
 * empty (empty collector result).
 */
interface HealthCheckInterface
{
    /**
     * Returns a single health contribution.
     *
     * @return array{component: string, status: string, detail?: mixed}
     *   status ∈ {up, degraded, down}.
     */
    public function check(): array;
}
