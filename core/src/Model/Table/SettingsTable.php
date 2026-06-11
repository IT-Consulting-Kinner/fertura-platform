<?php
declare(strict_types=1);

namespace App\Model\Table;

/**
 * Settings model (core.settings).
 *
 * Inherits UUIDv7 generation from AppTable; Footprint maintains created_by/updated_by.
 * The `value` column (jsonb) is explicitly typed as JSON so that arbitrary
 * values (int/bool/string/array) are transparently encoded/decoded.
 *
 * @method \App\Model\Entity\Setting newEmptyEntity()
 * @method \App\Model\Entity\Setting newEntity(array $data, array $options = [])
 */
class SettingsTable extends AppTable
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('settings');
        $this->setDisplayField('config_key');
        $this->setPrimaryKey('id');
        $this->addBehavior('Footprint');

        // Treat jsonb transparently as JSON (encode/decode of arbitrary values).
        $this->getSchema()->setColumnType('value', 'json');
    }
}
