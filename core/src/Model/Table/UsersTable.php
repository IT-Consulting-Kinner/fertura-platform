<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Users-Model (core.users).
 *
 * Zeitstempel werden in der Datenbank gepflegt (Defaults + Trigger
 * core.set_updated_at), daher kein Timestamp-Behavior.
 *
 * @method \App\Model\Entity\User newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\User get($primaryKey, array $options = [])
 */
class UsersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('users');
        $this->setDisplayField('username');
        $this->setPrimaryKey('id');

        // Assoziationen (Groups, UserAdminAreas, ApiTokens) folgen mit ihren
        // Table-Klassen in spaeteren Schritten (allowFallbackClass=false).
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('username')
            ->minLength('username', 3)
            ->maxLength('username', 100)
            ->requirePresence('username', 'create')
            ->notEmptyString('username');

        $validator
            ->email('email')
            ->requirePresence('email', 'create')
            ->notEmptyString('email');

        $validator
            ->scalar('first_name')
            ->maxLength('first_name', 100)
            ->allowEmptyString('first_name');

        $validator
            ->scalar('last_name')
            ->maxLength('last_name', 100)
            ->allowEmptyString('last_name');

        return $validator;
    }

    /**
     * Finder fuer den Auth-Resolver: nur aktive Benutzer koennen sich anmelden
     * (Kap. 27.15: deaktivierte/anonymisierte Benutzer erhalten keinen Zugriff).
     */
    public function findActive(SelectQuery $query, array $options = []): SelectQuery
    {
        return $query->where([$this->aliasField('status') => \App\Model\Entity\User::STATUS_ACTIVE]);
    }
}
