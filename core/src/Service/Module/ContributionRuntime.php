<?php
declare(strict_types=1);

namespace App\Service\Module;

use App\Infrastructure\Db;
use App\Service\Registry\ContractRegistry;
use RuntimeException;

/**
 * Führt Modul-Beiträge zu Erweiterungspunkten aus — **in-process** oder, bei
 * `out_of_process`-Modulen, **über RPC im isolierten Host** (Kap. 23.16.2,
 * Phase 3). Einheitliche Weiche für Collector (Health/Scheduled/Anonymize),
 * Event-Listener und (über {@see CapabilityHandle}) Resolver/Service.
 *
 * In-Process-Beiträge laufen im aktuellen Request-/Worker-Kontext (ambienter
 * RLS-Kontext der Default-Connection). Out-of-Process-Beiträge erhalten den
 * RLS-Kontext explizit über die RPC-Grenze ({@see RemoteInvoker::call()}).
 */
class ContributionRuntime
{
    public function __construct(private ?ContractRegistry $registry = null)
    {
        $this->registry ??= new ContractRegistry();
    }

    /** @return list<array{class:string, module_key:string, isolation:string}> */
    public function collectors(string $contract): array
    {
        return $this->withIsolation($this->registry->collectContributions($contract));
    }

    /** @return list<array{class:string, module_key:string, isolation:string}> */
    public function listeners(string $contract): array
    {
        return $this->withIsolation($this->registry->listenerContributions($contract));
    }

    /**
     * Ruft einen Beitrag auf. Out-of-Process → über den Host (RPC), sonst lokal.
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
     * Reichert die Beiträge um den Isolationsmodus ihres Moduls an (eine Abfrage).
     *
     * @param list<array{class:string, module_key:string}> $contribs
     * @return list<array{class:string, module_key:string, isolation:string}>
     */
    private function withIsolation(array $contribs): array
    {
        if ($contribs === []) {
            return [];
        }
        $keys = array_values(array_unique(array_map(static fn ($c) => $c['module_key'], $contribs)));
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
