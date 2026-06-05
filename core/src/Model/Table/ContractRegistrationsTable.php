<?php
declare(strict_types=1);

namespace App\Model\Table;

/**
 * @method \App\Model\Entity\ContractRegistration newEmptyEntity()
 */
class ContractRegistrationsTable extends AppTable
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('contract_registrations');
        $this->setDisplayField('module_key');
        $this->setPrimaryKey('id');
        $this->addBehavior('Footprint');
    }
}
