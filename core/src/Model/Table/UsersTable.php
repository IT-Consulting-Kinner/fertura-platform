<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Audit\AuditLogger;
use App\Model\Entity\User;
use App\Service\Privacy\AnonymizationService;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\Validation\Validator;

/**
 * Users model (core.users).
 *
 * Timestamps are maintained in the database (defaults + trigger
 * core.set_updated_at), hence no Timestamp behavior.
 *
 * @method \App\Model\Entity\User newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\User get($primaryKey, array $options = [])
 */
class UsersTable extends AppTable
{
    public function initialize(array $config): void
    {
        // AppTable enables the UuidV7 behavior (universal UUIDv7 generation).
        parent::initialize($config);

        $this->setTable('users');
        $this->setDisplayField('username');
        $this->setPrimaryKey('id');

        // Actor columns created_by/updated_by (E8) – domain tables only.
        $this->addBehavior('Footprint');

        // Associations (Groups, UserAdminAreas, ApiTokens) follow with their
        // Table classes in later steps (allowFallbackClass=false).
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
     * Finder for the auth resolver: only active users can sign in
     * (ch. 27.15: disabled/anonymized users get no access).
     */
    public function findActive(SelectQuery $query, array $options = []): SelectQuery
    {
        return $query->where([$this->aliasField('status') => User::STATUS_ACTIVE]);
    }

    /**
     * Irreversible anonymization of a user (right to erasure,
     * Decision 160 / ch. 27.15.3).
     *
     * Personal identity fields are replaced with non-reversible placeholders;
     * the technical ID, group memberships and historical references are kept.
     * No mapping table, no key -> not reversible. API tokens are revoked.
     * Everything in a single transaction (atomic).
     *
     * Note: The corresponding audit entry is added in Step 3 (audit log).
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
            $user->anonymized_at = new DateTime();

            if (!$this->save($user, ['checkRules' => false])) {
                return false;
            }

            // Revoke active API tokens (Decision 162: invalid immediately).
            $this->getConnection()->execute(
                'UPDATE api_tokens SET revoked_at = now() WHERE user_id = :uid AND revoked_at IS NULL',
                ['uid' => $id],
            );

            // Let modules scrub their own personal data
            // (ch. 27.15.3, collector core.collector.anonymize) — in the same
            // transaction (atomic). If any contribution fails, the whole
            // anonymization fails.
            $scrubbed = (new AnonymizationService())->run((string)$id, $this->getConnection());

            // Audit (ch. 27.18): log the anonymization. No PII in the
            // payload (E16); user referenced by UUID.
            (new AuditLogger())->log('user.anonymize', 'user', $id, [
                'oldValue' => ['status' => $previousStatus],
                'newValue' => ['status' => User::STATUS_ANONYMIZED, 'module_records_scrubbed' => $scrubbed],
            ]);

            return true;
        });
    }
}
