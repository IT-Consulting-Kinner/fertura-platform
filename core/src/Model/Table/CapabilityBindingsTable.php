<?php
declare(strict_types=1);

namespace App\Model\Table;

/**
 * @method \App\Model\Entity\CapabilityBinding newEmptyEntity()
 */
class CapabilityBindingsTable extends AppTable
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('capability_bindings');
        $this->setDisplayField('module_key');
        $this->setPrimaryKey('id');
        $this->addBehavior('Footprint');
    }
}
