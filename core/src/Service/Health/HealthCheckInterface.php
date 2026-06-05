<?php
declare(strict_types=1);

namespace App\Service\Health;

/**
 * Implementierungsvertrag für Modul-Health-Beiträge (Kap. 20.2.2).
 *
 * Module registrieren Implementierungen als Collector-Beiträge am
 * Core-Contract `core.collector.health`. Der Core aggregiert die Beiträge in
 * den Health-Endpoint. Ohne registrierte Beiträge bleibt das Ergebnis leer
 * (Collector-Leerergebnis).
 */
interface HealthCheckInterface
{
    /**
     * Liefert einen Health-Beitrag.
     *
     * @return array{component: string, status: string, detail?: mixed}
     *   status ∈ {up, degraded, down}.
     */
    public function check(): array;
}
