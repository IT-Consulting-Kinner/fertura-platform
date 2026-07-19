<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Module;

use App\Model\Entity\ContractRegistration;
use App\Service\Module\ModuleLifecycle;
use App\Service\Registry\ContractRegistry;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Reactivation cascade (inverse of the deactivation cascade, Decision 149): once a
 * provider module is active again it must RESTORE the docking integrations a prior
 * deactivation of it had severed — the consumer registrations of other modules that
 * were marked inactive while their own module stayed active. Without this a provider
 * deactivate→activate cycle leaves every docked connector permanently inert (its
 * module shows active but its registration + binding stay dead — the live symptom
 * the KB-AI-connector session reported after a host upgrade).
 *
 * Also pins the upsert invariant: cycling deactivate/activate must not pile up
 * duplicate (orphan) registration rows.
 */
class ModuleReactivationCascadeTest extends TestCase
{
    private const PROVIDER_KEY = 'zztest_react_ext';
    private const CONSUMER_KEY = 'zztest_react_con';
    private const CONTRACT = 'zztest_react.service.x';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    private function cleanup(): void
    {
        $conn = $this->conn();
        // Dropping the contract cascades to its registrations and bindings.
        $conn->execute("DELETE FROM contracts WHERE name LIKE 'zztest_react.%'");
        $conn->execute("DELETE FROM modules WHERE module_key LIKE 'zztest_react_%'");
    }

    private function makeModule(string $key, string $type, string $manifestJson, string $status = 'active'): void
    {
        $this->conn()->execute(
            'INSERT INTO modules (module_key, name, version, type, core_compatibility, status, manifest) '
            . "VALUES (:k, :n, '1.0.0', :t, '>=1.0.0', :s, CAST(:m AS jsonb))",
            ['k' => $key, 'n' => 'ZZ ' . $key, 't' => $type, 's' => $status, 'm' => $manifestJson],
        );
    }

    private function registrationActive(string $moduleKey): ?bool
    {
        $row = $this->conn()->execute(
            'SELECT cr.active FROM contract_registrations cr JOIN contracts c ON c.id = cr.contract_id '
            . 'WHERE c.name = :c AND cr.module_key = :m',
            ['c' => self::CONTRACT, 'm' => $moduleKey],
        )->fetch('assoc');

        return $row === false ? null : (bool)$row['active'];
    }

    private function registrationRowCount(string $moduleKey): int
    {
        $row = $this->conn()->execute(
            'SELECT count(*) AS c FROM contract_registrations cr JOIN contracts c ON c.id = cr.contract_id '
            . 'WHERE c.name = :c AND cr.module_key = :m',
            ['c' => self::CONTRACT, 'm' => $moduleKey],
        )->fetch('assoc');

        return (int)$row['c'];
    }

    /**
     * Builds the scenario: a SERVICE contract PROVIDED by an (activatable) main
     * module and CONSUMED (docked onto) by a connector. The provider carries a
     * valid manifest declaring the service so that activate() re-registers it; the
     * consumer is never activated here (its registration is created directly), so a
     * bare manifest suffices.
     */
    private function buildProviderConsumer(?string $consumerVersion = null): ContractRegistry
    {
        $providerManifest = (string)json_encode([
            'id' => self::PROVIDER_KEY,
            'name' => 'ZZ ' . self::PROVIDER_KEY,
            'version' => '1.0.0',
            'type' => 'main',
            'edition' => 'free',
            'description' => 'Reactivation cascade test provider.',
            'publisher' => 'Fertura Test',
            'php_namespace' => 'ZzReactExt',
            'core_compatibility' => '>=1.0.0',
            'services_registered' => [
                ['contract' => self::CONTRACT, 'class' => 'Zz\\React\\Provider'],
            ],
        ]);
        $this->makeModule(self::PROVIDER_KEY, 'main', $providerManifest);
        $this->makeModule(self::CONSUMER_KEY, 'extension', '{}');

        $registry = new ContractRegistry();
        $registry->registerContract(self::PROVIDER_KEY, self::CONTRACT, 'service', '1.0.0');
        $registry->register(self::PROVIDER_KEY, self::CONTRACT, ContractRegistration::TYPE_PROVIDER, [
            'implementationClass' => 'Zz\\React\\Provider',
            'moduleVersion' => '1.0.0',
        ]);
        $registry->register(self::CONSUMER_KEY, self::CONTRACT, ContractRegistration::TYPE_CONSUMER, [
            'moduleVersion' => '1.0.0',
            'requiredVersion' => $consumerVersion,
        ]);

        return $registry;
    }

    public function testMultipleCollectorClassesOnOneContractCoexist(): void
    {
        // A single module registers SEVERAL collector classes on ONE contract
        // (like Ticketing's seven scheduled tasks on core.collector.scheduled).
        // The upsert must key on implementation_class so each class keeps its own
        // registration instead of collapsing all-but-the-last into one row.
        $contract = 'zztest_react.collector.tasks';
        $this->makeModule(self::PROVIDER_KEY, 'extension', '{}');
        $registry = new ContractRegistry();
        $registry->registerContract(self::PROVIDER_KEY, $contract, 'collector', '1.0.0');
        foreach (['Zz\\Task\\A', 'Zz\\Task\\B', 'Zz\\Task\\C'] as $class) {
            $registry->register(self::PROVIDER_KEY, $contract, ContractRegistration::TYPE_COLLECTOR, [
                'implementationClass' => $class,
                'moduleVersion' => '1.0.0',
            ]);
        }
        // Re-registering an existing class is idempotent (upsert reuses its row).
        $registry->register(self::PROVIDER_KEY, $contract, ContractRegistration::TYPE_COLLECTOR, [
            'implementationClass' => 'Zz\\Task\\A',
            'moduleVersion' => '1.0.0',
        ]);

        $rows = $this->conn()->execute(
            'SELECT cr.implementation_class FROM contract_registrations cr '
            . 'JOIN contracts c ON c.id = cr.contract_id '
            . 'WHERE c.name = :c AND cr.module_key = :m AND cr.active ORDER BY 1',
            ['c' => $contract, 'm' => self::PROVIDER_KEY],
        )->fetchAll('assoc');
        $classes = array_map(static fn($r): string => (string)$r['implementation_class'], $rows);
        $this->assertSame(['Zz\\Task\\A', 'Zz\\Task\\B', 'Zz\\Task\\C'], $classes);
    }

    public function testReactivationSkipsVersionIncompatibleConsumer(): void
    {
        // Consumer requires the contract's 1.x line; provider is deactivated, the
        // contract is then bumped to an incompatible 2.0.0 while severed, and the
        // provider reactivated. reactivateRegistration must re-run register()'s
        // version gate and refuse to re-dock the now-incompatible consumer.
        $registry = $this->buildProviderConsumer('>=1.0.0 <2.0.0');
        $lifecycle = new ModuleLifecycle();

        $lifecycle->deactivate(self::PROVIDER_KEY);
        $this->assertFalse($this->registrationActive(self::CONSUMER_KEY));

        // Contract moves to a major the consumer's constraint excludes.
        $registry->upsertContract(self::PROVIDER_KEY, self::CONTRACT, 'service', '2.0.0');

        $lifecycle->activate(self::PROVIDER_KEY);

        // The provider is back, but the incompatible consumer stays severed...
        $this->assertTrue($this->registrationActive(self::PROVIDER_KEY));
        $this->assertFalse($this->registrationActive(self::CONSUMER_KEY));
        $this->assertFalse($registry->isBindingActive(self::CONSUMER_KEY, self::CONTRACT));
        // ...and the skip is audited.
        $failed = $this->conn()->execute(
            "SELECT count(*) AS c FROM audit_log WHERE action = 'module.integration_reactivate_failed' "
            . 'AND module_key = :m',
            ['m' => self::CONSUMER_KEY],
        )->fetch('assoc');
        $this->assertGreaterThanOrEqual(1, (int)$failed['c']);
    }

    public function testReactivatingProviderRestoresTheSeveredIntegration(): void
    {
        $registry = $this->buildProviderConsumer();
        $lifecycle = new ModuleLifecycle();

        $lifecycle->deactivate(self::PROVIDER_KEY);
        // Precondition: the deactivation cascade severed the consumer.
        $this->assertFalse($this->registrationActive(self::CONSUMER_KEY));
        $this->assertFalse($registry->isBindingActive(self::CONSUMER_KEY, self::CONTRACT));

        $lifecycle->activate(self::PROVIDER_KEY);

        // The provider is back...
        $this->assertTrue($this->registrationActive(self::PROVIDER_KEY));
        // ...and its docking integration is RESTORED (registration + binding).
        $this->assertTrue($this->registrationActive(self::CONSUMER_KEY));
        $this->assertTrue($registry->isBindingActive(self::CONSUMER_KEY, self::CONTRACT));

        // The restore is audited with the triggering (provider) module.
        $audited = $this->conn()->execute(
            "SELECT count(*) AS c FROM audit_log WHERE action = 'module.integration_reactivated' "
            . 'AND module_key = :m',
            ['m' => self::CONSUMER_KEY],
        )->fetch('assoc');
        $this->assertGreaterThanOrEqual(1, (int)$audited['c']);
    }

    public function testCyclingDoesNotAccumulateOrphanRegistrationRows(): void
    {
        $this->buildProviderConsumer();
        $lifecycle = new ModuleLifecycle();

        for ($i = 0; $i < 3; $i++) {
            $lifecycle->deactivate(self::PROVIDER_KEY);
            $lifecycle->activate(self::PROVIDER_KEY);
        }

        // The upsert reuses the same row each cycle — exactly one registration per
        // (module, contract, type) survives; no inactive orphans pile up.
        $this->assertSame(1, $this->registrationRowCount(self::PROVIDER_KEY));
        $this->assertSame(1, $this->registrationRowCount(self::CONSUMER_KEY));
        $this->assertTrue($this->registrationActive(self::PROVIDER_KEY));
        $this->assertTrue($this->registrationActive(self::CONSUMER_KEY));
    }
}
