<?php
declare(strict_types=1);

namespace App\Service\Registry;

/**
 * Laufzeit-Handle für eine gebundene Capability (Entscheidung 151).
 *
 * Ein Modul interagiert mit einem Contract ausschließlich über sein Handle.
 * Das Handle wird vom ContractRegistry nur ausgegeben, wenn für (Modul, Contract)
 * eine aktive Bindung besteht. `isValid()` prüft den Live-Status erneut, sodass
 * Deaktivierung/Widerruf sofort wirken.
 *
 * Hinweis: Die tatsächliche Instanziierung/der Aufruf der Implementierungsklasse
 * erfolgt, sobald Module existieren (ab Step 7). Step 5 liefert die Auflösung
 * (welche Klasse ist aktiver Provider / welche Beiträge / Listener).
 */
final class CapabilityHandle
{
    public function __construct(
        private readonly ContractRegistry $registry,
        public readonly string $moduleKey,
        public readonly string $contractName,
    ) {
    }

    public function isValid(): bool
    {
        return $this->registry->isBindingActive($this->moduleKey, $this->contractName);
    }

    /** Aktiver Provider (Resolver/Service) oder null (-> Default greift). */
    public function resolveProviderClass(): ?string
    {
        return $this->registry->resolveProviderClass($this->contractName);
    }

    /** @return list<string> Beiträge (Collector), nach Priorität. */
    public function contributionClasses(): array
    {
        return $this->registry->collectContributionClasses($this->contractName);
    }

    /** @return list<string> Listener (Event). */
    public function listenerClasses(): array
    {
        return $this->registry->listenerClasses($this->contractName);
    }
}
