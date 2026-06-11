<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\ORM\Entity;

/**
 * User (core.users).
 *
 * @property int $id
 * @property string $username
 * @property string $email
 * @property string|null $password_hash
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string $status
 * @property string|null $locale
 * @property string|null $timezone
 * @property \Cake\I18n\DateTime|null $anonymized_at
 * @property \Cake\I18n\DateTime $created_at
 * @property \Cake\I18n\DateTime $updated_at
 */
class User extends Entity
{
    public const STATUS_INVITED = 'invited';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_ANONYMIZED = 'anonymized';

    /**
     * Mass-assignable fields. password_hash and status are deliberately NOT
     * made directly mass-assignable; the password goes through setPassword().
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'username' => true,
        'email' => true,
        'first_name' => true,
        'last_name' => true,
        'locale' => true,
        'timezone' => true,
        '*' => false,
    ];

    /**
     * Never serialize/output.
     *
     * @var list<string>
     */
    protected array $_hidden = [
        'password_hash',
    ];

    /**
     * Sets the password as an Argon2id hash (Decision E13). The identifier also
     * verifies bcrypt hashes via fallback (future-proof on import).
     */
    public function setPassword(string $plain): void
    {
        $this->password_hash = (new DefaultPasswordHasher(['hashType' => PASSWORD_ARGON2ID]))->hash($plain);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
