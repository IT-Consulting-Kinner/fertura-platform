<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * Sessions-Tabelle (core.sessions) für den DB-gestützten, instanzübergreifenden
 * Session-Speicher (Kap. 20.8/30.7, HA). Wird von CakePHPs `DatabaseSession`
 * über den Alias `Sessions` genutzt. Eigene Modellklasse, damit der Alias auch
 * ohne generischen Fallback (z. B. bei `Orm.mappedClassesOnly`) auflösbar ist.
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
