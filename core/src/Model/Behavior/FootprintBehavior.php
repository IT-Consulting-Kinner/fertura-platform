<?php
declare(strict_types=1);

namespace App\Model\Behavior;

use ArrayObject;
use App\Model\ActorContext;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;

/**
 * Maintains the actor columns created_by/updated_by from the ActorContext
 * (Decision E8). Complements – does not replace – the dedicated audit log (Step 3).
 *
 * If no actor is set (CLI/system), the columns are left untouched (NULL).
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

        $schema = $this->_table->getSchema();
        $createdField = (string)$this->getConfig('createdBy');
        $updatedField = (string)$this->getConfig('updatedBy');

        // Column-tolerant: only set if the table actually has the column.
        // created_by = "by whom it was created" (including append-only joins),
        // updated_by = "by whom it was last changed" (editable records only).
        if ($schema->hasColumn($createdField) && $entity->isNew() && $entity->get($createdField) === null) {
            $entity->set($createdField, $actorId);
        }
        if ($schema->hasColumn($updatedField)) {
            $entity->set($updatedField, $actorId);
        }
    }
}
