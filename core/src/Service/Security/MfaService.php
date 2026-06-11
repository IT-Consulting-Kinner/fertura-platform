<?php
declare(strict_types=1);

namespace App\Service\Security;

use App\Audit\AuditLogger;
use App\Infrastructure\Uuid;
use App\Service\Cache\CacheStore;
use App\Service\Settings\SecretCipher;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;

/**
 * MFA management (TOTP) for local accounts (ch. 27.16.3 addendum):
 *
 * - **Enrollment** is two-stage: generate the secret → the user confirms with a
 *   valid code → only then is the secret persisted (AES-encrypted via
 *   SecretCipher) and MFA activated. This also produces **single-use recovery
 *   codes** (only SHA-256 hashes stored in the DB; plaintext shown once).
 * - **Verification** is timing-safe with a ±1 time window and **replay
 *   protection** (the same time step is not accepted twice per user).
 * - **SSO is unaffected**: MFA applies to local password login; under
 *   federation the IdP enforces the MFA policy.
 */
class MfaService
{
    public const RECOVERY_CODE_COUNT = 8;
    public const ISSUER = 'Fertura';

    private SecretCipher $cipher;
    private AuditLogger $audit;
    private CacheStore $replay;

    public function __construct(?SecretCipher $cipher = null, ?AuditLogger $audit = null, ?CacheStore $replay = null)
    {
        $this->cipher = $cipher ?? new SecretCipher();
        $this->audit = $audit ?? new AuditLogger();
        // Short-lived "last accepted time step" state (replay protection);
        // _app_ is sufficient (TTL >> 2 time steps of 30 s each).
        $this->replay = $replay ?? new CacheStore('_app_');
    }

    private function conn(): \Cake\Database\Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /** Is MFA active for this user? */
    public function enabled(string $userId): bool
    {
        if (!Uuid::isValid($userId)) {
            return false;
        }
        $row = $this->conn()->execute(
            'SELECT totp_enabled_at FROM users WHERE id = :id',
            ['id' => $userId],
        )->fetch('assoc');

        return $row !== false && $row['totp_enabled_at'] !== null;
    }

    /** Is MFA enforced for local logins (operator setting)? */
    public function required(): bool
    {
        try {
            return (bool)(new SettingsManager())->get('core', 'security.mfa.required', false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Completes enrollment: validates the confirmation code against the (not yet
     * persisted) secret and activates MFA. On success returns the **plaintext
     * recovery codes** (shown once), otherwise null.
     *
     * @return list<string>|null
     */
    public function confirmEnrollment(string $userId, string $base32Secret, string $code): ?array
    {
        if (!Uuid::isValid($userId) || Totp::verify($base32Secret, $code) === null) {
            return null;
        }
        $conn = $this->conn();
        $codes = $this->generateRecoveryCodes();

        $conn->transactional(function () use ($conn, $userId, $base32Secret, $codes): void {
            $conn->execute(
                'UPDATE users SET totp_secret = :s, totp_enabled_at = now() WHERE id = :id',
                ['s' => $this->cipher->encrypt($base32Secret), 'id' => $userId],
            );
            $conn->execute('DELETE FROM user_mfa_recovery_codes WHERE user_id = :u', ['u' => $userId]);
            foreach ($codes as $plain) {
                // The NORMALISED form is hashed (no hyphens/spaces, uppercase) —
                // identical to the normalisation applied on redemption.
                $conn->execute(
                    'INSERT INTO user_mfa_recovery_codes (user_id, code_hash) VALUES (:u, :h)',
                    ['u' => $userId, 'h' => hash('sha256', str_replace('-', '', $plain))],
                );
            }
        });
        $this->audit->log('mfa.enable', 'user', $userId, ['newValue' => ['method' => 'totp']]);

        return $codes;
    }

    /**
     * Verifies a TOTP code (with replay protection) OR a recovery code
     * (single-use, atomically invalidated).
     */
    public function verify(string $userId, string $code): bool
    {
        if (!Uuid::isValid($userId)) {
            return false;
        }
        $code = trim($code);

        // Recovery code? (Format heuristic: not 6 numeric digits.)
        if (!preg_match('/^[0-9]{6}\z/', $code)) {
            return $this->redeemRecoveryCode($userId, $code);
        }

        $row = $this->conn()->execute(
            'SELECT totp_secret FROM users WHERE id = :id AND totp_enabled_at IS NOT NULL',
            ['id' => $userId],
        )->fetch('assoc');
        if ($row === false || $row['totp_secret'] === null) {
            return false;
        }
        try {
            $secret = $this->cipher->decrypt((string)$row['totp_secret']);
        } catch (\Throwable) {
            return false; // wrong key/tampering -> fail-closed
        }

        $step = Totp::verify($secret, $code);
        if ($step === null) {
            return false;
        }
        // Replay protection: do not accept the same (or an older) time step
        // again — an intercepted code is therefore not reusable.
        $key = 'mfa.last_step.' . $userId;
        $last = (int)$this->replay->get($key, 0);
        if ($step <= $last) {
            return false;
        }
        $this->replay->set($key, $step);

        return true;
    }

    /** Disables MFA (the caller has already re-authenticated the user). */
    public function disable(string $userId): void
    {
        if (!Uuid::isValid($userId)) {
            return;
        }
        $conn = $this->conn();
        $conn->execute(
            'UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL WHERE id = :id',
            ['id' => $userId],
        );
        $conn->execute('DELETE FROM user_mfa_recovery_codes WHERE user_id = :u', ['u' => $userId]);
        $this->audit->log('mfa.disable', 'user', $userId, []);
    }

    /** Number of recovery codes still unused. */
    public function recoveryCodesLeft(string $userId): int
    {
        if (!Uuid::isValid($userId)) {
            return 0;
        }
        return (int)$this->conn()->execute(
            'SELECT count(*) AS c FROM user_mfa_recovery_codes WHERE user_id = :u AND used_at IS NULL',
            ['u' => $userId],
        )->fetch('assoc')['c'];
    }

    /** Redeems a recovery code atomically (single-use). */
    private function redeemRecoveryCode(string $userId, string $code): bool
    {
        $normalized = strtoupper(str_replace([' ', '-'], '', $code));
        if ($normalized === '' || strlen($normalized) > 64) {
            return false;
        }
        $n = $this->conn()->execute(
            'UPDATE user_mfa_recovery_codes SET used_at = now() '
            . 'WHERE user_id = :u AND code_hash = :h AND used_at IS NULL',
            ['u' => $userId, 'h' => hash('sha256', $normalized)],
        )->rowCount();
        if ($n > 0) {
            $this->audit->log('mfa.recovery_used', 'user', $userId, [
                'newValue' => ['left' => $this->recoveryCodesLeft($userId)],
            ]);

            return true;
        }

        return false;
    }

    /** @return list<string> 8 codes of 10 characters each (Crockford-like, easy to transcribe). */
    private function generateRecoveryCodes(): array
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTVWXYZ23456789'; // without I/L/O/0/1/U (confusable)
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $code = '';
            for ($j = 0; $j < 10; $j++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($code, 0, 5) . '-' . substr($code, 5);
        }

        return $codes;
    }
}
