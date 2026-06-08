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
        if ($contract === null || $contract->contract_type !== 'service') {
            throw new CapabilityRejectedException(
                "Kein aufrufbares Service-Interface: '{$this->contractName}'."
            );
        }

        // Out-of-Process-Anbieter (Kap. 23.16): transparent über RPC an den
        // isolierten Modulprozess statt In-Process-Instanziierung.
        $remoteKey = $this->outOfProcessProvider();
        if ($remoteKey !== null) {
            return (new \App\Service\Module\RemoteInvoker())->invoke($remoteKey, $this->contractName, $input);
        }

        $class = $this->registry->resolveProviderClass($this->contractName);
        if ($class === null) {
            // Anbieter deaktiviert/entfernt -> Interface nicht verfügbar (Kap. 29.14).
            throw new CapabilityRejectedException(
                "Interface-Aufruf abgewiesen: kein aktiver Anbieter für '{$this->contractName}'."
            );
        }
        if (!class_exists($class)) {
            throw new CapabilityRejectedException("Anbieterklasse nicht ladbar: $class");
        }

        $impl = new $class();
        if (!$impl instanceof ServiceInterface) {
            throw new CapabilityRejectedException(
                "Anbieterklasse implementiert kein ServiceInterface: $class"
            );
        }

        return $impl->handle($input);
    }

    /**
     * Liefert den Modul-Schlüssel des Anbieters, wenn dieser im Modus
     * `out_of_process` läuft (Kap. 23.16) und aktiv ist – sonst null.
     *
     * Der Aufruf wird dann transparent über {@see RemoteInvoker} an den
     * isolierten Modulprozess geleitet, statt In-Process zu instanziieren.
     */
    private function outOfProcessProvider(): ?string
    {
        $row = ConnectionManager::get('default')->execute(
            'SELECT m.module_key FROM core.contracts c '
            . 'JOIN core.modules m ON m.module_key = c.owner_module_key '
            . "WHERE c.name = :name AND m.isolation = 'out_of_process' AND m.status = 'active'",
            ['name' => $this->contractName],
        )->fetch('assoc');

        return $row === false ? null : (string)$row['module_key'];
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
