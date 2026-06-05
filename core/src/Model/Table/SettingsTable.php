<?php
declare(strict_types=1);

namespace App\Model\Table;

/**
 * Settings-Model (core.settings).
 *
 * Erbt UUIDv7-Erzeugung von AppTable; Footprint pflegt created_by/updated_by.
 * Die `value`-Spalte (jsonb) wird explizit als JSON typisiert, damit beliebige
 * Werte (int/bool/string/Array) transparent kodiert/dekodiert werden.
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

        // jsonb transparent als JSON behandeln (Encode/Decode beliebiger Werte).
        $this->getSchema()->setColumnType('value', 'json');
    }
}
