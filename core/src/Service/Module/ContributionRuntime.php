<?php
declare(strict_types=1);

namespace App\Service\Module;

use App\Infrastructure\Db;
use App\Service\Registry\ContractRegistry;
use RuntimeException;

/**
 * Executes module contributions to extension points — **in-process** or, for
 * `out_of_process` modules, **via RPC in the isolated host** (ch. 23.16.2,
 * phase 3). A single dispatch point for collectors (Health/Scheduled/Anonymize),
 * event listeners and (via {@see CapabilityHandle}) resolvers/services.
 *
 * In-process contributions run in the current request/worker context (the
 * ambient RLS context of the default connection). Out-of-process contributions
 * receive the RLS context explicitly across the RPC boundary
 * ({@see RemoteInvoker::call()}).
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
     * @return list<array{class:string, module_key:string, isolation:string}>
     */
    public function collectors(string $contract, bool $tenantScoped = true): array
    {
        return $this->withIsolation($this->registry->collectContributions($contract, $tenantScoped));
    }

    /**
     * Event listeners for a contract. Per-tenant gated by default: a module the
     * firing tenant disabled does not handle that tenant's events (system events
     * with no tenant fire all listeners — fail-open).
     *
     * @return list<array{class:string, module_key:string, isolation:string}>
     */
    public function listeners(string $contract, bool $tenantScoped = true): array
    {
        return $this->withIsolation($this->registry->listenerContributions($contract, $tenantScoped));
    }

    /**
     * Invokes a contribution. Out-of-process → via the host (RPC), otherwise
     * locally.
     *
     * @param array{class:string, module_key:string, isolation?:string} $contrib
     * @param list<mixed> $args
     * @param array{user_id?:?string,group_ids?:list<string>,bypass?:bool}|null $rls
     */
    public function call(array $contrib, string $method, array $args, ?array $rls = null): mixed
    {
        if (($contrib['isolation'] ?? 'in_process') === 'out_of_process') {
            return (new RemoteInvoker())->call($contrib['module_key'], $contrib['class'], $method, $args, $rls);
        }
        $class = $contrib['class'];
        if (!class_exists($class)) {
            throw new RuntimeException("Beitragsklasse nicht ladbar: $class");
        }

        return (new $class())->$method(...$args);
    }

    /**
     * Enriches the contributions with their module's isolation mode (one query).
     *
     * @param list<array{class:string, module_key:string}> $contribs
     * @return list<array{class:string, module_key:string, isolation:string}>
     */
    private function withIsolation(array $contribs): array
    {
        if ($contribs === []) {
            return [];
        }
        $keys = array_values(array_unique(array_map(static fn($c) => $c['module_key'], $contribs)));
        $names = [];
        $params = [];
        foreach ($keys as $i => $k) {
            $names[] = ":k$i";
            $params["k$i"] = $k;
        }
        $iso = [];
        $rows = Db::privileged()->execute(
            'SELECT module_key, isolation FROM modules WHERE module_key IN (' . implode(',', $names) . ')',
            $params,
        )->fetchAll('assoc');
        foreach ($rows as $r) {
            $iso[(string)$r['module_key']] = (string)$r['isolation'];
        }
        foreach ($contribs as &$c) {
            $c['isolation'] = $iso[$c['module_key']] ?? 'in_process';
        }

        return $contribs;
    }
}
