<?php
declare(strict_types=1);

namespace App\Model\Table;

/**
 * @method \App\Model\Entity\Contract newEmptyEntity()
 * @method \App\Model\Entity\Contract newEntity(array $data, array $options = [])
 */
class ContractsTable extends AppTable
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('contracts');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');
        $this->addBehavior('Footprint');

        foreach (['input_spec', 'output_spec', 'default_behavior'] as $jsonField) {
            $this->getSchema()->setColumnType($jsonField, 'json');
        }
    }
}
