<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\Entity\Contract;
use App\Model\Entity\ContractRegistration;
use App\Service\Registry\ContractRegistry;
use App\Service\Registry\RegistryException;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Self-test of the contract/capability registry (Step 5) without real modules.
 * Registers example contracts/registrations, exercises the core behaviours and
 * cleans up afterwards. Serves as a smoke test until the real module lifecycle
 * (Step 7) drives the registry.
 */
class RegistrySelftestCommand extends Command
{
    private int $failures = 0;

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $registry = new ContractRegistry();
        $this->cleanup();

        try {
            // 1. Resolver contract + provider + slot exclusivity.
            $registry->registerContract('selftest', 'selftest.resolver.demo', Contract::TYPE_RESOLVER, '2.1.0');
            $registry->register('modA', 'selftest.resolver.demo', ContractRegistration::TYPE_PROVIDER, [
                'implementationClass' => 'ModA\\Resolver',
                'requiredVersion' => '>=2.0.0 <3.0.0',
            ]);
            $this->assert($io, $registry->resolveProviderClass('selftest.resolver.demo') === 'ModA\\Resolver', 'aktiver Provider = ModA');
            $this->assertThrows($io, fn () => $registry->register('modB', 'selftest.resolver.demo', ContractRegistration::TYPE_PROVIDER, [
                'implementationClass' => 'ModB\\Resolver',
            ]), 'zweiter Provider -> Slot-Konflikt');

            // 2. Capability handles (guard).
            $this->assert($io, $registry->handleFor('modA', 'selftest.resolver.demo') !== null, 'modA hat gueltiges Handle');
            $this->assert($io, $registry->handleFor('modX', 'selftest.resolver.demo') === null, 'modX (ungebunden) -> kein Handle');

            // 3. Version matching (ch. 26.6.4).
            $registry->registerContract('selftest', 'selftest.service.api', Contract::TYPE_SERVICE, '2.3.0');
            $registry->register('modC', 'selftest.service.api', ContractRegistration::TYPE_CONSUMER, ['requiredVersion' => '>=2.1.0 <3.0.0']);
            $this->assert($io, true, 'kompatibler Consumer (>=2.1.0 <3.0.0 vs 2.3.0) ok');
            $this->assertThrows($io, fn () => $registry->register('modD', 'selftest.service.api', ContractRegistration::TYPE_CONSUMER, ['requiredVersion' => '3.0.0']), 'inkompatible Version (3.0.0 vs 2.3.0) -> Fehler');
            $this->assertThrows($io, fn () => $registry->register('modE', 'selftest.service.api', ContractRegistration::TYPE_CONSUMER, ['requiredVersion' => '^2.0.0']), 'Caret-Kurzform -> unzulaessig');

            // 4. Collector with priority.
            $registry->registerContract('selftest', 'selftest.collector.widgets', Contract::TYPE_COLLECTOR, '1.0.0');
            $registry->register('modF', 'selftest.collector.widgets', ContractRegistration::TYPE_COLLECTOR, ['implementationClass' => 'ModF\\Widget', 'priority' => 10]);
            $registry->register('modG', 'selftest.collector.widgets', ContractRegistration::TYPE_COLLECTOR, ['implementationClass' => 'ModG\\Widget', 'priority' => 20]);
            $this->assert($io, $registry->collectContributionClasses('selftest.collector.widgets') === ['ModG\\Widget', 'ModF\\Widget'], 'Collector-Beitraege nach Prioritaet sortiert');

            // 5. Unknown contract -> error.
            $this->assertThrows($io, fn () => $registry->register('modH', 'selftest.does.not.exist', ContractRegistration::TYPE_LISTENER), 'unbekannter Contract -> Fehler');
        } catch (Throwable $e) {
            $this->assert($io, false, 'unerwarteter Fehler: ' . $e->getMessage());
        }

        // 6. Deactivate the active modA provider -> resolve = null, handle invalid.
        $this->deactivateProvider($registry, 'selftest.resolver.demo', 'modA');
        $this->assert($io, $registry->resolveProviderClass('selftest.resolver.demo') === null, 'nach Deaktivierung kein Provider (Default greift)');
        $this->assert($io, $registry->handleFor('modA', 'selftest.resolver.demo') === null, 'Handle nach Deaktivierung ungueltig');

        // 7. Audit trails present.
        $this->assert($io, $this->auditCount('resolver.conflict') >= 1, 'Audit: resolver.conflict');
        $this->assert($io, $this->auditCount('contract.version_incompatible') >= 1, 'Audit: version_incompatible');
        $this->assert($io, $this->auditCount('contract.register') >= 3, 'Audit: contract.register (>=3)');

        $this->cleanup();

        if ($this->failures > 0) {
            $io->error("Selbsttest FEHLGESCHLAGEN ({$this->failures} Fehler).");

            return static::CODE_ERROR;
        }
        $io->success('Registry-Selbsttest bestanden.');

        return static::CODE_SUCCESS;
    }

    private function assert(ConsoleIo $io, bool $cond, string $label): void
    {
        if ($cond) {
            $io->out("  <success>OK</success>   $label");
        } else {
            $io->out("  <error>FAIL</error> $label");
            $this->failures++;
        }
    }

    private function assertThrows(ConsoleIo $io, callable $fn, string $label): void
    {
        try {
            $fn();
            $this->assert($io, false, $label . ' (keine Exception)');
        } catch (Throwable) {
            $this->assert($io, true, $label);
        }
    }

    private function deactivateProvider(ContractRegistry $registry, string $contractName, string $moduleKey): void
    {
        $connection = ConnectionManager::get('default');
        $row = $connection->execute(
            'SELECT r.id FROM contract_registrations r JOIN contracts c ON c.id = r.contract_id '
            . "WHERE c.name = :n AND r.module_key = :m AND r.registration_type = 'provider' AND r.active",
            ['n' => $contractName, 'm' => $moduleKey],
        )->fetch('assoc');
        if ($row) {
            $registry->deactivateRegistration($row['id']);
        }
    }

    private function auditCount(string $action): int
    {
        $row = ConnectionManager::get('default')
            ->execute('SELECT count(*) AS c FROM audit_log WHERE action = :a', ['a' => $action])
            ->fetch('assoc');

        return (int)($row['c'] ?? 0);
    }

    private function cleanup(): void
    {
        ConnectionManager::get('default')->execute("DELETE FROM contracts WHERE owner_module_key = 'selftest'");
    }
}
