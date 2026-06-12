<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use Cake\Datasource\ConnectionManager;

/**
 * Read-only view into the contract registry (admin area "Registry / Contracts").
 *
 * Read-only: contracts, their registrations, and active capability bindings.
 * Registration itself happens declaratively via module manifests.
 */
class RegistryController extends AdminController
{
    protected ?string $requiredArea = 'registry_contracts';

    public function index(): void
    {
        $conn = ConnectionManager::get('default');
        $contracts = $conn->execute(
            'SELECT c.id, c.name, c.contract_type, c.version, c.owner_module_key, c.multi_use, c.active, '
            . '(SELECT count(*) FROM contract_registrations r WHERE r.contract_id = c.id AND r.active) AS reg_count '
            . 'FROM contracts c ORDER BY c.contract_type, c.name',
        )->fetchAll('assoc');
        $registrations = $conn->execute(
            'SELECT c.name AS contract, r.module_key, r.registration_type, r.implementation_class, r.priority, r.active '
            . 'FROM contract_registrations r JOIN contracts c ON c.id = r.contract_id '
            . 'ORDER BY c.name, r.priority DESC',
        )->fetchAll('assoc');
        $bindings = $conn->execute(
            'SELECT cb.module_key, c.name AS contract, cb.status '
            . 'FROM capability_bindings cb JOIN contracts c ON c.id = cb.contract_id ORDER BY cb.module_key',
        )->fetchAll('assoc');
        $this->set(compact('contracts', 'registrations', 'bindings'));
    }

    /**
     * Interface registry (ch. 29.12): a view of the contract registry filtered
     * to service contracts – one row per offered interface including its active
     * consumer count, plus a view per consuming module.
     */
    public function interfaces(): void
    {
        $conn = ConnectionManager::get('default');
        $interfaces = $conn->execute(
            'SELECT c.id, c.name, c.version, c.owner_module_key, c.multi_use, c.active, c.description, '
            . 'c.input_spec, c.output_spec, c.default_behavior, '
            . '(SELECT module_key FROM contract_registrations r WHERE r.contract_id = c.id '
            . "AND r.registration_type = 'provider' AND r.active LIMIT 1) AS provider, "
            . '(SELECT count(*) FROM contract_registrations r WHERE r.contract_id = c.id '
            . "AND r.registration_type = 'service_consumer' AND r.active) AS active_consumers "
            . "FROM contracts c WHERE c.contract_type = 'service' ORDER BY c.name",
        )->fetchAll('assoc');

        // View per consuming module (ch. 29.12.2): status + compatibility.
        $usages = $conn->execute(
            'SELECT r.module_key, r.module_version, c.name AS interface, c.version AS interface_version, '
            . 'r.required_version, r.active, '
            . '(SELECT cb.status FROM capability_bindings cb '
            . 'WHERE cb.module_key = r.module_key AND cb.contract_id = c.id LIMIT 1) AS binding_status '
            . 'FROM contract_registrations r JOIN contracts c ON c.id = r.contract_id '
            . "WHERE c.contract_type = 'service' AND r.registration_type = 'service_consumer' "
            . 'ORDER BY r.module_key, c.name',
        )->fetchAll('assoc');

        $this->set(compact('interfaces', 'usages'));
    }
}
