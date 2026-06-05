<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\ORM\Entity;

/**
 * Benutzer (core.users).
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
     * Massenzuweisbare Felder. password_hash und Status werden bewusst NICHT
     * direkt massenzuweisbar gemacht; das Passwort laeuft ueber setPassword().
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
     * Niemals serialisieren/ausgeben.
     *
     * @var list<string>
     */
    protected array $_hidden = [
        'password_hash',
    ];

    /**
     * Setzt das Passwort als Argon2id-Hash (Entscheidung E13). Der Identifier
     * verifiziert via Fallback auch bcrypt-Hashes (zukunftssicher bei Import).
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
