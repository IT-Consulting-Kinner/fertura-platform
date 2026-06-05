<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Audit\AuditLogger;
use App\Model\Entity\User;
use Cake\ORM\Query\SelectQuery;
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
class UsersTable extends AppTable
{
    public function initialize(array $config): void
    {
        // AppTable aktiviert das UuidV7-Behavior (universelle UUIDv7-Erzeugung).
        parent::initialize($config);

        $this->setTable('users');
        $this->setDisplayField('username');
        $this->setPrimaryKey('id');

        // Akteur-Spalten created_by/updated_by (E8) – nur Fachtabellen.
        $this->addBehavior('Footprint');

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
        return $query->where([$this->aliasField('status') => User::STATUS_ACTIVE]);
    }

    /**
     * Irreversible Anonymisierung eines Benutzers (Recht auf Löschung,
     * Entscheidung 160 / Kap. 27.15.3).
     *
     * Personenbezogene Identitätsfelder werden durch nicht rückführbare
     * Platzhalter ersetzt; technische ID, Gruppenmitgliedschaften und
     * historische Referenzen bleiben erhalten. Keine Zuordnungstabelle,
     * kein Schlüssel -> nicht umkehrbar. API-Tokens werden widerrufen.
     * Alles in einer Transaktion (atomar).
     *
     * Hinweis: Der zugehörige Audit-Eintrag wird in Step 3 (Audit-Log) ergänzt.
     */
    public function anonymize(User $user): bool
    {
        return (bool)$this->getConnection()->transactional(function () use ($user): bool {
            $id = $user->id;
            $previousStatus = $user->status;
            $user->username = 'geloeschter_benutzer_' . $id;
            $user->email = 'anonymized-' . $id . '@invalid.local';
            $user->first_name = null;
            $user->last_name = null;
            $user->locale = null;
            $user->timezone = null;
            $user->password_hash = null;
            $user->status = User::STATUS_ANONYMIZED;
            $user->anonymized_at = new \Cake\I18n\DateTime();

            if (!$this->save($user, ['checkRules' => false])) {
                return false;
            }

            // Aktive API-Tokens widerrufen (Entscheidung 162: sofort ungültig).
            $this->getConnection()->execute(
                'UPDATE api_tokens SET revoked_at = now() WHERE user_id = :uid AND revoked_at IS NULL',
                ['uid' => $id],
            );

            // Audit (Kap. 27.18): Anonymisierung protokollieren. Keine PII im
            // Payload (E16); Benutzer per UUID referenziert.
            (new AuditLogger())->log('user.anonymize', 'user', $id, [
                'oldValue' => ['status' => $previousStatus],
                'newValue' => ['status' => User::STATUS_ANONYMIZED],
            ]);

            return true;
        });
    }
}
