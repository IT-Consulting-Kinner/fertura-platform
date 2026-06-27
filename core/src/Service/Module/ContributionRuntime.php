<?php
declare(strict_types=1);

namespace App\Service\Module;

use App\Service\Registry\ContractRegistry;
use App\Service\Storage\ModuleStorageScope;
use RuntimeException;

/**
 * Executes module contributions to extension points, **in-process**. A single
 * dispatch point for collectors (Health/Scheduled/Anonymize), event listeners and
 * (via {@see \App\Service\Registry\CapabilityHandle}) resolvers/services.
 *
 * Contributions run in the current request/worker context (the ambient RLS context
 * of the default connection). Modules are trusted in-process code, gated at install
 * time by mandatory signature + review + the static capability allowlist; there is no
 * runtime isolation tier.
 */
class ContributionRuntime
{
    public function __construct(private ?ContractRegistry $registry = null)
    {
        $this->registry ??= new ContractRegistry();
    }

    /**
     * Collector contributions for a contract. Per-tenant gated by default
     * (operator/tenant authz §5 Phase 2): only modules enabled for the current
     * tenant contribute; with no tenant context nothing is filtered (fail-open).
     * Platform/privacy consumers (health, search reindex, anonymization) pass
     * `$tenantScoped = false` to collect across ALL modules.
     *
     * @return list<array{class:string, module_key:string}>
     */
    public function collectors(string $contract, bool $tenantScoped = true): array
    {
        return $this->registry->collectContributions($contract, $tenantScoped);
    }

    /**
     * Event listeners for a contract. Per-tenant gated by default: a module the
     * firing tenant disabled does not handle that tenant's events (system events
     * with no tenant fire all listeners — fail-open).
     *
     * @return list<array{class:string, module_key:string}>
     */
    public function listeners(string $contract, bool $tenantScoped = true): array
    {
        return $this->registry->listenerContributions($contract, $tenantScoped);
    }

    /**
     * Invokes a contribution in-process: `$class::$method(...$args)`. The RLS row
     * context that applies is whatever the caller already set on the connection
     * (the request/worker transaction); a contribution that must run RLS-bypassed
     * sets `app.bypass_rls` ambiently before calling (see AnonymizationService).
     *
     * @param array{class:string, module_key:string} $contrib
     * @param list<mixed> $args
     */
    public function call(array $contrib, string $method, array $args): mixed
    {
        $class = $contrib['class'];
        if (!class_exists($class)) {
            throw new RuntimeException("Beitragsklasse nicht ladbar: $class");
        }
        $moduleKey = (string)$contrib['module_key'];
        // 'core' contributions are Core's own trusted code (its scheduled tasks etc.) —
        // no storage scope. For a real module, set the storage scope so StorageManager
        // refuses any write outside tenant/<id>/<moduleKey>/ (Inc 8b); restore the
        // previous scope afterwards so a module-calls-module capability nests correctly.
        if ($moduleKey === '' || $moduleKey === 'core') {
            return (new $class())->$method(...$args);
        }
        $previousScope = ModuleStorageScope::enter($moduleKey);
        try {
            return (new $class())->$method(...$args);
        } finally {
            ModuleStorageScope::leave($previousScope);
        }
    }
}
