<?php
declare(strict_types=1);

namespace App\Service\Registry;

use App\Audit\AuditLogger;
use App\Model\Entity\CapabilityBinding;
use App\Model\Entity\Contract;
use App\Model\Entity\ContractRegistration;
use App\Model\Table\CapabilityBindingsTable;
use App\Model\Table\ContractRegistrationsTable;
use App\Model\Table\ContractsTable;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Central contract/capability registry (Step 5, ch. 26).
 *
 * Responsible for: registering contracts as well as provider/collector
 * contributions, listeners and service consumers; validation (existence, version
 * compatibility per 26.6.4, slot exclusivity); persisted capability bindings plus
 * runtime handles (Decision 151); and resolution (active provider / contributions
 * / listeners). Every operation is audited (ch. 26.17).
 *
 * License/signature checking is Step 8; modules are referenced by module_key.
 */
class ContractRegistry
{
    use LocatorAwareTrait;

    private AuditLogger $audit;

    public function __construct(?AuditLogger $audit = null)
    {
        $this->audit = $audit ?? new AuditLogger();
    }

    private function contracts(): ContractsTable
    {
        return $this->fetchTable('Contracts');
    }

    private function registrations(): ContractRegistrationsTable
    {
        return $this->fetchTable('ContractRegistrations');
    }

    private function bindings(): CapabilityBindingsTable
    {
        return $this->fetchTable('CapabilityBindings');
    }

    public function findContract(string $name): ?Contract
    {
        return $this->contracts()->find()->where(['name' => $name])->first();
    }

    // ---- Contract definition -------------------------------------------------

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

    // ---- Registration against a contract ------------------------------------

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
                    "Inkompatible Version für $contractName: gefordert $requiredVersion, angeboten {$contract->version}.",
                );
            }
        }

        // Slot exclusivity for providers (clean error + audit; the partial
        // unique index is the DB-side safety net).
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
                    "Resolver-Slot belegt: $contractName (aktiver Provider: {$existing->module_key}).",
                );
            }
        }

        // Multi-use rule for public module interfaces (ch. 29.8.1):
        // with multi_use=false only one consuming module may be active at a time.
        if ($registrationType === ContractRegistration::TYPE_CONSUMER && !$contract->multi_use) {
            $existing = $this->registrations()->find()
                ->where([
                    'contract_id' => $contract->id,
                    'registration_type' => ContractRegistration::TYPE_CONSUMER,
                    'active' => true,
                    'module_key !=' => $moduleKey,
                ])
                ->first();
            if ($existing !== null) {
                $this->audit->log('interface.multiuse_conflict', 'contract', $contractName, [
                    'newValue' => ['active_consumer' => $existing->module_key, 'rejected_module' => $moduleKey],
                    'moduleKey' => $moduleKey,
                ]);
                throw new RegistryException(
                    "Mehrfachnutzung untersagt: $contractName (aktiver Nutzer: {$existing->module_key}).",
                );
            }
        }

        $registrations = $this->registrations();

        return $registrations->getConnection()->transactional(function () use (
            $registrations,
            $contract,
            $moduleKey,
            $registrationType,
            $opts,
            $requiredVersion,
            $contractName,
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
                "Registrierungsart '$registrationType' passt nicht zu Contract-Typ '$contractType'.",
            );
        }
    }

    // ---- Lifecycle -----------------------------------------------------------

    public function deactivateRegistration(string $registrationId): void
    {
        $registrations = $this->registrations();
        $r = $registrations->get($registrationId);
        $contract = $this->contracts()->get($r->contract_id);

        $registrations->getConnection()->transactional(function () use ($registrations, $r, $contract): void {
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

    // ---- Resolution ----------------------------------------------------------

    public function resolveProviderClass(string $contractName): ?string
    {
        return $this->resolveProvider($contractName)['class'] ?? null;
    }

    /**
     * Active provider (class **and** contributing module) of a contract.
     * For the out-of-process routing the **providing** module is what counts
     * (not the contract owner — for resolver/service contracts it may be a
     * different module).
     *
     * @return array{class:string, module_key:string}|null
     */
    public function resolveProvider(string $contractName): ?array
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
        if ($r === null || $r->implementation_class === null) {
            return null;
        }

        return ['class' => (string)$r->implementation_class, 'module_key' => (string)$r->module_key];
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

    /**
     * Like {@see collectContributionClasses()}, but with the contributing module
     * per class (for the out-of-process routing, ch. 23.16.2).
     *
     * @return list<array{class: string, module_key: string}>
     */
    public function collectContributions(string $contractName): array
    {
        return $this->activeContributions($contractName, ContractRegistration::TYPE_COLLECTOR);
    }

    /** @return list<array{class: string, module_key: string}> */
    public function listenerContributions(string $contractName): array
    {
        return $this->activeContributions($contractName, ContractRegistration::TYPE_LISTENER);
    }

    /** @return list<array{class: string, module_key: string}> */
    private function activeContributions(string $contractName, string $registrationType): array
    {
        $c = $this->findContract($contractName);
        if ($c === null || !$c->active) {
            return [];
        }
        $rows = $this->registrations()->find()
            ->where(['contract_id' => $c->id, 'registration_type' => $registrationType, 'active' => true])
            ->orderBy(['priority' => 'DESC', 'created_at' => 'ASC'])
            ->all();
        $out = [];
        foreach ($rows as $row) {
            if ($row->implementation_class !== null) {
                $out[] = ['class' => (string)$row->implementation_class, 'module_key' => (string)$row->module_key];
            }
        }

        return $out;
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
     * Issues a handle only if an active binding exists (guard, Decision 151).
     * Optionally re-checks the version.
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
