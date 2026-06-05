<?php
declare(strict_types=1);

namespace App\Service\Registry;

/**
 * Vertrag für die Implementierung eines öffentlichen Modul-Interfaces
 * (Service-Contract, Kap. 29.3 / 26.3.4).
 *
 * Das anbietende Main-Modul stellt die Implementierung bereit; nutzende Module
 * rufen sie ausschließlich über ein {@see CapabilityHandle} auf (Kap. 29.8.3).
 * Eingabe und Ausgabe sind maschinenlesbare, assoziative Strukturen gemäß der
 * Input-/Output-Spezifikation des Contracts (Kap. 29.6). Die fachliche Semantik
 * definiert allein das anbietende Modul (Kap. 29.6.3).
 */
interface ServiceInterface
{
    /**
     * Bearbeitet einen Interface-Aufruf.
     *
     * @param array<string, mixed> $input Eingabe gemäß Input-Spezifikation.
     * @return array<string, mixed> Antwort gemäß Output-Spezifikation.
     */
    public function handle(array $input): array;
}
