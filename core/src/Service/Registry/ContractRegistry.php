<?php
declare(strict_types=1);

namespace App\Service\Registry;

use App\Audit\AuditLogger;
use App\Model\Entity\CapabilityBinding;
use App\Model\Entity\Contract;
use App\Model\Entity\ContractRegistration;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Zentrale Contract-/Capability-Registry (Step 5, Kap. 26).
 *
 * Verantwortlich für: Registrierung von Contracts und von Provider/Collector-
 * Beiträgen/Listenern/Service-Nutzern, Validierung (Existenz, Versions-
 * Kompatibilität nach 26.6.4, Slot-Exklusivität), persistierte Capability-
 * Bindings + Laufzeit-Handles (Entscheidung 151) sowie Auflösung (aktiver
 * Provider / Beiträge / Listener). Jeder Vorgang wird auditiert (Kap. 26.17).
 *
 * Lizenz-/Signaturprüfung ist Step 8; Module werden per module_key referenziert.
 */
class ContractRegistry
{
    use LocatorAwareTrait;

    private AuditLogger $audit;

    public function __construct(?AuditLogger $audit = null)
    {
        $this->audit = $audit ?? new AuditLogger();
    }

    private function contracts(): \App\Model\Table\ContractsTable
    {
        return $this->fetchTable('Contracts');
    }

    private function registrations(): \App\Model\Table\ContractRegistrationsTable
    {
        return $this->fetchTable('ContractRegistrations');
    }

    private function bindings(): \App\Model\Table\CapabilityBindingsTable
    {
        return $this->fetchTable('CapabilityBindings');
    }

    public function findContract(string $name): ?Contract
    {
        return $this->contracts()->find()->where(['name' => $name])->first();
    }

    // ---- Contract-Definition -------------------------------------------------

    public function registerContract(
        string $ownerModuleKey,
        string $name,
        string $type,
        string $version,
        array $opts = [],
    ): Contract {
        $valid = [Contract::TYPE_RESOLVER, Contract::TYPE_COLLECTOR, Contract::TYPE_EVENT, Contract::TYPE_SERVICE];
        if (!in_array($type, $valid, true)) {
            throw new RegistryException("Unbekannter Contract-Typ: $type");
        }
        SemVer::parse($version);
        if ($this->findContract($name) !== null) {
            throw new RegistryException("Contract existiert bereits: $name");
        }

        $contracts = $this->contracts();

        return $contracts->getConnection()->transactional(function () use ($contracts, $ownerModuleKey, $name, $type, $version, $opts) {
            $c = $contracts->newEmptyEntity();
            $c->set('owner_module_key', $ownerModuleKey);
            $c->set('name', $name);
            $c->set('contract_type', $type);
            $c->set('version', $version);
            $c->set('input_spec', $opts['inputSpec'] ?? null);
            $c->set('output_spec', $opts['outputSpec'] ?? null);
            $c->set('default_behavior', $opts['defaultBehavior'] ?? null);
            $c->set('multi_use', $opts['multiUse'] ?? true);
            $c->set('description', $opts['description'] ?? null);
            $c->set('active', true);
            if (!$contracts->save($c)) {
                throw new RegistryException('Contract konnte nicht gespeichert werden.');
            }
            $this->audit->log('contract.register', 'contract', $name, [
                'newValue' => ['type' => $type, 'version' => $version, 'owner' => $ownerModuleKey],
                'moduleKey' => $ownerModuleKey,
            ]);

            return $c;
        });
    }

    // ---- Registrierung an einem Contract ------------------------------------

    public function register(
        string $moduleKey,
        string $contractName,
        string $registrationType,
        array $opts = [],
    ): ContractRegistration {
        $validTypes = [
            ContractRegistration::TYPE_PROVIDER,
            ContractRegistration::TYPE_COLLECTOR,
            ContractRegistration::TYPE_LISTENER,
            ContractRegistration::TYPE_CONSUMER,
        ];
        if (!in_array($registrationType, $validTypes, true)) {
            throw new RegistryException("Unbekannte Registrierungsart: $registrationType");
        }

        $contract = $this->findContract($contractName);
        if ($contract === null) {
            $this->audit->log('contract.validation_failed', 'contract', $contractName, [
                'newValue' => ['reason' => 'unknown_contract', 'module' => $moduleKey],
                'moduleKey' => $moduleKey,
            ]);
            throw new RegistryException("Unbekannter Contract: $contractName");
        }

        $this->assertTypeMatch($contract->contract_type, $registrationType);

        $requiredVersion = $opts['requiredVersion'] ?? null;
        if ($requiredVersion !== null) {
            $constraint = VersionConstraint::parse($requiredVersion);
            if (!$constraint->isSatisfiedBy(SemVer::parse($contract->version))) {
                $this->audit->log('contract.version_incompatible', 'contract', $contractName, [
                    'newValue' => ['required' => $requiredVersion, 'offered' => $contract->version, 'module' => $moduleKey],
                    'moduleKey' => $moduleKey,
                ]);
                throw new RegistryException(
                    "Inkompatible Version für $contractName: gefordert $requiredVersion, angeboten {$contract->version}."
                );
            }
        }

        // Slot-Exklusivität für Provider (sauberer Fehler + Audit; der partielle
        // Unique-Index ist das DB-seitige Sicherheitsnetz).
        if ($registrationType === ContractRegistration::TYPE_PROVIDER) {
            $existing = $this->registrations()->find()
                ->where([
                    'contract_id' => $contract->id,
                    'registration_type' => ContractRegistration::TYPE_PROVIDER,
                    'active' => true,
                ])
                ->first();
            if ($existing !== null) {
                $this->audit->log('resolver.conflict', 'contract', $contractName, [
                    'newValue' => ['active_provider' => $existing->module_key, 'rejected_module' => $moduleKey],
                    'moduleKey' => $moduleKey,
                ]);
                throw new RegistryException(
                    "Resolver-Slot belegt: $contractName (aktiver Provider: {$existing->module_key})."
                );
            }
        }

        $registrations = $this->registrations();

        return $registrations->getConnection()->transactional(function () use (
            $registrations, $contract, $moduleKey, $registrationType, $opts, $requiredVersion, $contractName
        ) {
            $r = $registrations->newEmptyEntity();
            $r->set('contract_id', $contract->id);
            $r->set('module_key', $moduleKey);
            $r->set('module_version', $opts['moduleVersion'] ?? null);
            $r->set('registration_type', $registrationType);
            $r->set('implementation_class', $opts['implementationClass'] ?? null);
            $r->set('required_version', $requiredVersion);
            $r->set('priority', (int)($opts['priority'] ?? 0));
            $r->set('active', true);
            if (!$registrations->save($r)) {
                throw new RegistryException('Registrierung konnte nicht gespeichert werden.');
            }

            $this->issueBinding($moduleKey, (string)$contract->id, $requiredVersion);

            $action = match ($registrationType) {
                ContractRegistration::TYPE_PROVIDER => 'provider.register',
                ContractRegistration::TYPE_COLLECTOR => 'collector.register',
                ContractRegistration::TYPE_LISTENER => 'listener.register',
                ContractRegistration::TYPE_CONSUMER => 'service_consumer.register',
            };
            $this->audit->log($action, 'contract_registration', $contractName, [
                'newValue' => [
                    'module' => $moduleKey,
                    'type' => $registrationType,
                    'impl' => $opts['implementationClass'] ?? null,
                ],
                'moduleKey' => $moduleKey,
            ]);

            return $r;
        });
    }

    private function assertTypeMatch(string $contractType, string $registrationType): void
    {
        $allowed = match ($contractType) {
            Contract::TYPE_RESOLVER => [ContractRegistration::TYPE_PROVIDER],
            Contract::TYPE_COLLECTOR => [ContractRegistration::TYPE_COLLECTOR],
            Contract::TYPE_EVENT => [ContractRegistration::TYPE_LISTENER],
            Contract::TYPE_SERVICE => [ContractRegistration::TYPE_PROVIDER, ContractRegistration::TYPE_CONSUMER],
            default => [],
        };
        if (!in_array($registrationType, $allowed, true)) {
            throw new RegistryException(
                "Registrierungsart '$registrationType' passt nicht zu Contract-Typ '$contractType'."
            );
        }
    }

    // ---- Lifecycle -----------------------------------------------------------

    public function deactivateRegistration(string $registrationId): void
    {
        $registrations = $this->registrations();
        $r = $registrations->get($registrationId);
        $contract = $this->contracts()->get($r->contract_id);

        $registrations->getConnection()->transactional(function () use ($registrations, $r, $contract) {
            $r->set('active', false);
            $r->set('deactivated_at', new DateTime());
            $registrations->save($r);
            $this->revokeBinding($r->module_key, (string)$r->contract_id);
            $this->audit->log('registration.deactivate', 'contract_registration', $contract->name, [
                'oldValue' => ['active' => true],
                'newValue' => ['active' => false],
                'moduleKey' => $r->module_key,
            ]);
        });
    }

    // ---- Auflösung -----------------------------------------------------------

    public function resolveProviderClass(string $contractName): ?string
    {
        $c = $this->findContract($contractName);
        if ($c === null || !$c->active) {
            return null;
        }
        $r = $this->registrations()->find()
            ->where([
                'contract_id' => $c->id,
                'registration_type' => ContractRegistration::TYPE_PROVIDER,
                'active' => true,
            ])
            ->first();

        return $r?->implementation_class;
    }

    /** @return list<string> */
    public function collectContributionClasses(string $contractName): array
    {
        return $this->activeImplClasses($contractName, ContractRegistration::TYPE_COLLECTOR);
    }

    /** @return list<string> */
    public function listenerClasses(string $contractName): array
    {
        return $this->activeImplClasses($contractName, ContractRegistration::TYPE_LISTENER);
    }

    /** @return list<string> */
    private function activeImplClasses(string $contractName, string $registrationType): array
    {
        $c = $this->findContract($contractName);
        if ($c === null || !$c->active) {
            return [];
        }
        $rows = $this->registrations()->find()
            ->where(['contract_id' => $c->id, 'registration_type' => $registrationType, 'active' => true])
            ->orderBy(['priority' => 'DESC', 'created_at' => 'ASC'])
            ->all();

        $classes = [];
        foreach ($rows as $row) {
            if ($row->implementation_class !== null) {
                $classes[] = $row->implementation_class;
            }
        }

        return $classes;
    }

    // ---- Capability-Bindings -------------------------------------------------

    private function issueBinding(string $moduleKey, string $contractId, ?string $requiredVersion): void
    {
        $bindings = $this->bindings();
        $row = $bindings->find()
            ->where(['module_key' => $moduleKey, 'contract_id' => $contractId])
            ->first();
        if ($row === null) {
            $row = $bindings->newEmptyEntity();
            $row->set('module_key', $moduleKey);
            $row->set('contract_id', $contractId);
        }
        $row->set('required_version', $requiredVersion);
        $row->set('status', CapabilityBinding::STATUS_ACTIVE);
        $row->set('revoked_at', null);
        $bindings->save($row);
    }

    private function revokeBinding(string $moduleKey, string $contractId): void
    {
        $row = $this->bindings()->find()
            ->where(['module_key' => $moduleKey, 'contract_id' => $contractId])
            ->first();
        if ($row !== null) {
            $row->set('status', CapabilityBinding::STATUS_REVOKED);
            $row->set('revoked_at', new DateTime());
            $this->bindings()->save($row);
        }
    }

    public function isBindingActive(string $moduleKey, string $contractName): bool
    {
        $c = $this->findContract($contractName);
        if ($c === null || !$c->active) {
            return false;
        }

        return $this->bindings()->find()
            ->where([
                'module_key' => $moduleKey,
                'contract_id' => $c->id,
                'status' => CapabilityBinding::STATUS_ACTIVE,
            ])
            ->first() !== null;
    }

    /**
     * Gibt ein Handle nur aus, wenn eine aktive Bindung besteht (Guard,
     * Entscheidung 151). Optional erneute Versionsprüfung.
     */
    public function handleFor(string $moduleKey, string $contractName, ?string $requiredVersion = null): ?CapabilityHandle
    {
        if (!$this->isBindingActive($moduleKey, $contractName)) {
            return null;
        }
        if ($requiredVersion !== null) {
            $c = $this->findContract($contractName);
            if ($c === null || !VersionConstraint::parse($requiredVersion)->isSatisfiedBy(SemVer::parse($c->version))) {
                return null;
            }
        }

        return new CapabilityHandle($this, $moduleKey, $contractName);
    }
}
