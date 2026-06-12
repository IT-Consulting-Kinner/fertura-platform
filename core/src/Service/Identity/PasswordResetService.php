<?php
declare(strict_types=1);

namespace App\Service\Identity;

use App\Audit\AuditLogger;
use App\Model\Entity\User;
use App\Service\Settings\SettingsManager;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Invitation/password-set token (ch. 27.2/27.15).
 *
 * Creates a one-time token (stored only as a SHA-256 hash) with which a user
 * sets their password and – if "invited" – is activated. Sending the link
 * (email) is module scope; the core provides creation + redemption.
 */
class PasswordResetService
{
    use LocatorAwareTrait;

    public function __construct(
        private ?SettingsManager $settings = null,
        private ?AuditLogger $audit = null,
    ) {
        $this->settings ??= new SettingsManager();
        $this->audit ??= new AuditLogger();
    }

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /**
     * Creates a token and returns the plaintext (for the link).
     *
     * @return string Plaintext token (available only once).
     */
    public function create(string $userId, string $purpose = 'invite', int $ttlHours = 72, ?string $createdBy = null): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $token);
        $expires = (new DateTime())->modify("+{$ttlHours} hours");

        $this->conn()->execute(
            'INSERT INTO password_reset_tokens (user_id, token_hash, purpose, expires_at, created_by) '
            . 'VALUES (:u, :h, :p, :e, :cb)',
            ['u' => $userId, 'h' => $hash, 'p' => $purpose, 'e' => $expires->format('Y-m-d H:i:sP'), 'cb' => $createdBy],
        );
        $this->audit->log('user.invite_token', 'user', $userId, ['newValue' => ['purpose' => $purpose]]);

        return $token;
    }

    /** Minimum length from the configuration (ch. 27.16.3). */
    public function minPasswordLength(): int
    {
        return (int)$this->settings->get('core', 'password.min_length', 12);
    }

    /**
     * Redeems a token: sets the password, activates "invited" users, and
     * consumes the token. Returns an error message or null (success).
     */
    public function redeem(string $token, string $newPassword): ?string
    {
        $min = $this->minPasswordLength();
        if (strlen($newPassword) < $min) {
            return __('passwordreset.too_short', $min);
        }

        $hash = hash('sha256', $token);
        $row = $this->conn()->execute(
            'SELECT id, user_id, expires_at, used_at FROM password_reset_tokens WHERE token_hash = :h',
            ['h' => $hash],
        )->fetch('assoc');
        if ($row === false) {
            return __('passwordreset.token_invalid');
        }
        if ($row['used_at'] !== null) {
            return __('passwordreset.token_used');
        }
        if (strtotime((string)$row['expires_at']) < time()) {
            return __('passwordreset.token_expired');
        }

        $users = $this->fetchTable('Users');
        $user = $users->find()->where(['id' => $row['user_id']])->first();
        if ($user === null || $user->get('status') === User::STATUS_ANONYMIZED) {
            return __('passwordreset.user_unavailable');
        }

        return $this->conn()->transactional(function () use ($users, $user, $newPassword, $row): ?string {
            $user->setPassword($newPassword);
            if ($user->get('status') === User::STATUS_INVITED) {
                $user->set('status', User::STATUS_ACTIVE);
            }
            if (!$users->save($user, ['checkRules' => false])) {
                return __('passwordreset.save_failed');
            }
            $this->conn()->execute(
                'UPDATE password_reset_tokens SET used_at = now() WHERE id = :id',
                ['id' => $row['id']],
            );
            // Invalidate any other open tokens of the same user.
            $this->conn()->execute(
                'UPDATE password_reset_tokens SET used_at = now() WHERE user_id = :u AND used_at IS NULL',
                ['u' => $row['user_id']],
            );
            $this->audit->log('user.password_set', 'user', (string)$user->get('id'), [
                'newValue' => ['status' => $user->get('status')],
            ]);

            return null;
        });
    }
}
