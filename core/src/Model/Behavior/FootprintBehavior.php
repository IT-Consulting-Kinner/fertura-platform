<?php
declare(strict_types=1);

namespace App\Model\Behavior;

use ArrayObject;
use App\Model\ActorContext;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;

/**
 * Pflegt Akteur-Spalten created_by/updated_by aus dem ActorContext
 * (Entscheidung E8). Ergänzt – ersetzt nicht – das dedizierte Audit-Log (Step 3).
 *
 * Ist kein Akteur gesetzt (CLI/System), bleiben die Spalten unangetastet (NULL).
 */
class FootprintBehavior extends Behavior
{
    protected array $_defaultConfig = [
        'createdBy' => 'created_by',
        'updatedBy' => 'updated_by',
    ];

    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $actorId = ActorContext::get();
        if ($actorId === null) {
            return;
        }

        $createdField = (string)$this->getConfig('createdBy');
        $updatedField = (string)$this->getConfig('updatedBy');

        if ($entity->isNew() && $entity->get($createdField) === null) {
            $entity->set($createdField, $actorId);
        }
        $entity->set($updatedField, $actorId);
    }
}
