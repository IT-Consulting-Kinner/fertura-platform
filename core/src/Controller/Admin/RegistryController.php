<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use Cake\Datasource\ConnectionManager;

/**
 * Contract-Registry-Einsicht (Administrationsbereich „Registry / Contracts").
 *
 * Lesend: Contracts, ihre Registrierungen und aktive Capability-Bindings.
 * Die Registrierung selbst erfolgt deklarativ über Modul-Manifeste.
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
}
