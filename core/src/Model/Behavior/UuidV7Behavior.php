<?php
declare(strict_types=1);

namespace App\Model\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;
use Symfony\Component\Uid\Uuid;

/**
 * Erzeugt für neue Datensätze eine zeitgeordnete UUIDv7 als Primärschlüssel
 * (Entscheidung E6), damit das ORM die ID sofort kennt. Die Datenbank besitzt
 * zusätzlich einen DEFAULT (core.uuid_generate_v7()) als Netz für Inserts
 * außerhalb des ORM.
 */
class UuidV7Behavior extends Behavior
{
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $primaryKey = $this->_table->getPrimaryKey();
        if (!is_string($primaryKey)) {
            return;
        }
        if ($entity->isNew() && $entity->get($primaryKey) === null) {
            $entity->set($primaryKey, Uuid::v7()->toRfc4122());
        }
    }
}
