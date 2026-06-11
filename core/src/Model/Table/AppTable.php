<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * Base class for all core tables.
 *
 * Enables universal, time-ordered UUIDv7 generation (Decision E6) for every
 * table with a single-column uuid primary key. The UuidV7Behavior checks the PK
 * type itself and leaves tables with text/composite PKs (e.g. admin_areas)
 * untouched. This keeps the convention consistent without each Table class
 * having to register the behavior individually.
 */
class AppTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->addBehavior('UuidV7');
    }
}
