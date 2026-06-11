<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * Sessions table (core.sessions) for the DB-backed, cross-instance session store
 * (ch. 20.8/30.7, HA). Used by CakePHP's `DatabaseSession` via the `Sessions`
 * alias. Dedicated model class so the alias resolves even without a generic
 * fallback (e.g. when `Orm.mappedClassesOnly` is set).
 */
class SessionsTable extends Table
{
    /**
     * @param array<string, mixed> $config
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('sessions');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');
    }
}
