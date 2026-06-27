<?php
declare(strict_types=1);

namespace App\Service\Registry;

use App\Service\Module\ContributionRuntime;

/**
 * Runtime handle for a bound capability (Decision 151).
 *
 * A module interacts with a contract exclusively through its handle. The handle
 * is only issued by the ContractRegistry when an active binding exists for the
 * (module, contract) pair. `isValid()` re-checks the live status, so a
 * deactivation/revocation takes effect immediately.
 *
 * For service contracts (public module interfaces, ch. 29), the consuming module
 * invokes the provider's implementation exclusively through {@see self::invoke()}.
 * Access control holds by construction: only a valid handle is usable
 * (ch. 29.8.3); otherwise the rejection behavior (ch. 29.8.4) takes effect via
 * {@see CapabilityRejectedException}.
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
     * Invokes the public module interface (service contract) (ch. 29.8).
     *
     * Acts as a guard: without a valid binding or an active provider, the call is
     * *technically* rejected (ch. 29.8.4). The provider class must implement
     * {@see ServiceInterface}.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws \App\Service\Registry\CapabilityRejectedException
     */
    public function invoke(array $input): array
    {
        if (!$this->isValid()) {
            throw new CapabilityRejectedException(
                'Interface-Aufruf abgewiesen: keine gültige Bindung für Modul '
                . "'{$this->moduleKey}' an '{$this->contractName}'.",
            );
        }

        $contract = $this->registry->findContract($this->contractName);
        // Service and (data) resolver contracts are callable: both are consumed
        // through the provider method handle(input):array (ch. 26/29).
        if ($contract === null || !in_array($contract->contract_type, ['service', 'resolver'], true)) {
            throw new CapabilityRejectedException(
                "Kein aufrufbares Interface (Service/Resolver): '{$this->contractName}'.",
            );
        }

        $provider = $this->registry->resolveProvider($this->contractName);
        if ($provider === null) {
            // Provider deactivated/removed -> interface unavailable (ch. 29.14).
            throw new CapabilityRejectedException(
                "Interface-Aufruf abgewiesen: kein aktiver Anbieter für '{$this->contractName}'.",
            );
        }

        return (array)(new ContributionRuntime($this->registry))->call($provider, 'handle', [$input]);
    }

    /** Active provider (resolver/service) or null (-> default applies). */
    public function resolveProviderClass(): ?string
    {
        return $this->registry->resolveProviderClass($this->contractName);
    }

    /** @return list<string> Contributions (collector), by priority. */
    public function contributionClasses(): array
    {
        return $this->registry->collectContributionClasses($this->contractName);
    }

    /** @return list<string> Listeners (event). */
    public function listenerClasses(): array
    {
        return $this->registry->listenerClasses($this->contractName);
    }
}
