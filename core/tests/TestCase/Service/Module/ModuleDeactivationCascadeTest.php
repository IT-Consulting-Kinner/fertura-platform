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
 * Deactivation cascade (Decision 149 / ch. 23.5.5): deactivating a module that
 * provides a contract must mark the integrations docking onto it (other modules'
 * CONSUMER registrations — the connector-on-extension case) inactive, while
 * leaving the consuming modules themselves active (their base operability must
 * not be destroyed).
 */
class ModuleDeactivationCascadeTest extends TestCase
{
    private const PROVIDER_KEY = 'zztest_casc_ext';
    private const CONSUMER_KEY = 'zztest_casc_con';
    private const OTHER_KEY = 'zztest_casc_other';
    private const CONTRACT = 'zztest_casc.service.x';

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
        // Dropping the contract cascades to its registrations and bindings (FK ON DELETE CASCADE).
        $conn->execute("DELETE FROM contracts WHERE name LIKE 'zztest_casc.%'");
        $conn->execute("DELETE FROM modules WHERE module_key LIKE 'zztest_casc_%'");
        // audit_log is append-only (immutable trigger) — never deleted; its rows
        // for these keys accumulate harmlessly, hence the >= assertion below.
    }

    private function makeActiveModule(string $key, string $type): void
    {
        $this->conn()->execute(
            'INSERT INTO modules (module_key, name, version, type, core_compatibility, status, manifest) '
            . "VALUES (:k, :n, '1.0.0', :t, '>=1.0.0', 'active', '{}'::jsonb)",
            ['k' => $key, 'n' => 'ZZ ' . $key, 't' => $type],
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

    private function moduleStatus(string $key): ?string
    {
        $row = $this->conn()->execute(
            'SELECT status FROM modules WHERE module_key = :k',
            ['k' => $key],
        )->fetch('assoc');

        return $row === false ? null : (string)$row['status'];
    }

    /**
     * Builds the scenario: a SERVICE contract provided by the extension and
     * consumed (docked onto) by the connector.
     */
    private function buildProviderConsumer(): ContractRegistry
    {
        $this->makeActiveModule(self::PROVIDER_KEY, 'extension');
        $this->makeActiveModule(self::CONSUMER_KEY, 'extension');

        $registry = new ContractRegistry();
        $registry->registerContract(self::PROVIDER_KEY, self::CONTRACT, 'service', '1.0.0');
        $registry->register(self::PROVIDER_KEY, self::CONTRACT, ContractRegistration::TYPE_PROVIDER, [
            'implementationClass' => 'Zz\\Casc\\Provider',
            'moduleVersion' => '1.0.0',
        ]);
        $registry->register(self::CONSUMER_KEY, self::CONTRACT, ContractRegistration::TYPE_CONSUMER, [
            'moduleVersion' => '1.0.0',
        ]);

        return $registry;
    }

    public function testDeactivatingProviderSeversTheDockingIntegration(): void
    {
        $registry = $this->buildProviderConsumer();

        // Pre: both registrations active, the consumer holds a live binding.
        $this->assertTrue($this->registrationActive(self::PROVIDER_KEY));
        $this->assertTrue($this->registrationActive(self::CONSUMER_KEY));
        $this->assertTrue($registry->isBindingActive(self::CONSUMER_KEY, self::CONTRACT));

        (new ModuleLifecycle())->deactivate(self::PROVIDER_KEY);

        // The provider's own registration is gone...
        $this->assertFalse($this->registrationActive(self::PROVIDER_KEY));
        // ...and so is the consumer's docking integration (the cascade)...
        $this->assertFalse($this->registrationActive(self::CONSUMER_KEY));
        $this->assertFalse($registry->isBindingActive(self::CONSUMER_KEY, self::CONTRACT));
        // ...but the consuming module itself stays operable (Decision 149).
        $this->assertSame('active', $this->moduleStatus(self::CONSUMER_KEY));
        // The cascade is audited with the triggering module.
        $audited = $this->conn()->execute(
            "SELECT count(*) AS c FROM audit_log WHERE action = 'module.integration_deactivated' "
            . 'AND module_key = :m',
            ['m' => self::CONSUMER_KEY],
        )->fetch('assoc');
        $this->assertGreaterThanOrEqual(1, (int)$audited['c']);
    }

    public function testDeactivatingAnUnrelatedModuleLeavesTheIntegrationIntact(): void
    {
        $registry = $this->buildProviderConsumer();
        $this->makeActiveModule(self::OTHER_KEY, 'extension');

        // A module that provides nothing the consumer uses must not affect it.
        (new ModuleLifecycle())->deactivate(self::OTHER_KEY);

        $this->assertTrue($this->registrationActive(self::CONSUMER_KEY));
        $this->assertTrue($registry->isBindingActive(self::CONSUMER_KEY, self::CONTRACT));
    }
}
