<?php
declare(strict_types=1);

namespace App\Service\Registry;

use Cake\Datasource\ConnectionManager;

/**
 * Laufzeit-Handle für eine gebundene Capability (Entscheidung 151).
 *
 * Ein Modul interagiert mit einem Contract ausschließlich über sein Handle.
 * Das Handle wird vom ContractRegistry nur ausgegeben, wenn für (Modul, Contract)
 * eine aktive Bindung besteht. `isValid()` prüft den Live-Status erneut, sodass
 * Deaktivierung/Widerruf sofort wirken.
 *
 * Für Service-Contracts (öffentliche Modul-Interfaces, Kap. 29) ruft das
 * nutzende Modul die Implementierung des Anbieters ausschließlich über
 * {@see self::invoke()} auf. Die Zugriffskontrolle wirkt durch Konstruktion:
 * Nur ein gültiges Handle ist nutzbar (Kap. 29.8.3); andernfalls greift das
 * Abweisungsverhalten (Kap. 29.8.4) via {@see CapabilityRejectedException}.
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

    /**
     * Ruft das öffentliche Modul-Interface (Service-Contract) auf (Kap. 29.8).
     *
     * Wirkt als Guard: Ohne gültige Bindung bzw. ohne aktiven Anbieter wird der
     * Aufruf *technisch* abgewiesen (Kap. 29.8.4). Die Anbieterklasse muss
     * {@see ServiceInterface} implementieren.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws CapabilityRejectedException
     */
    public function invoke(array $input): array
    {
        if (!$this->isValid()) {
            throw new CapabilityRejectedException(
                "Interface-Aufruf abgewiesen: keine gültige Bindung für Modul "
                . "'{$this->moduleKey}' an '{$this->contractName}'."
            );
        }

        $contract = $this->registry->findContract($this->contractName);
        // Aufrufbar sind Service- und (Daten-)Resolver-Contracts: beide werden
        // über die Anbieter-Methode handle(input):array genutzt (Kap. 26/29).
        if ($contract === null || !in_array($contract->contract_type, ['service', 'resolver'], true)) {
            throw new CapabilityRejectedException(
                "Kein aufrufbares Interface (Service/Resolver): '{$this->contractName}'."
            );
        }

        $provider = $this->registry->resolveProvider($this->contractName);
        if ($provider === null) {
            // Anbieter deaktiviert/entfernt -> Interface nicht verfügbar (Kap. 29.14).
            throw new CapabilityRejectedException(
                "Interface-Aufruf abgewiesen: kein aktiver Anbieter für '{$this->contractName}'."
            );
        }

        // Out-of-Process-Anbieter (Kap. 23.16.2): transparent über RPC an den
        // isolierten Modulprozess; sonst in-process. Maßgeblich ist das
        // **Anbieter**-Modul (nicht der Contract-Owner).
        $contrib = $provider + ['isolation' => $this->providerIsolation($provider['module_key'])];

        return (array)(new \App\Service\Module\ContributionRuntime($this->registry))->call($contrib, 'handle', [$input]);
    }

    /** Isolationsmodus des Anbieter-Moduls (core/unbekannt -> in_process). */
    private function providerIsolation(string $moduleKey): string
    {
        if ($moduleKey === '' || $moduleKey === 'core') {
            return 'in_process';
        }
        $row = ConnectionManager::get('default')->execute(
            "SELECT isolation FROM core.modules WHERE module_key = :k AND status = 'active'",
            ['k' => $moduleKey],
        )->fetch('assoc');

        return $row === false ? 'in_process' : (string)$row['isolation'];
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
