<?php
declare(strict_types=1);

namespace App\Model\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;
use Symfony\Component\Uid\Uuid;

/**
 * Generates a time-ordered UUIDv7 as the primary key for new records
 * (Decision E6), so the ORM knows the ID immediately. The database additionally
 * has a DEFAULT (core.uuid_generate_v7()) as a safety net for inserts made
 * outside the ORM.
 */
class UuidV7Behavior extends Behavior
{
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $primaryKey = $this->_table->getPrimaryKey();
        // Single-column uuid primary keys only: text/composite PKs
        // (e.g. admin_areas.area_key) are left untouched. This makes the behavior
        // universally applicable (see AppTable).
        if (!is_string($primaryKey)) {
            return;
        }
        if ($this->_table->getSchema()->getColumnType($primaryKey) !== 'uuid') {
            return;
        }
        if ($entity->isNew() && $entity->get($primaryKey) === null) {
            $entity->set($primaryKey, Uuid::v7()->toRfc4122());
        }
    }
}
