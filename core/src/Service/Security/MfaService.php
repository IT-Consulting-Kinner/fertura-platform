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
 * MFA-Verwaltung (TOTP) für lokale Konten (Kap. 27.16.3-Ergänzung):
 *
 * - **Enrollment** zweistufig: Secret erzeugen → Benutzer bestätigt mit einem
 *   gültigen Code → erst dann wird das Secret (AES-verschlüsselt, SecretCipher)
 *   persistiert und MFA aktiv. Dabei entstehen **Einmal-Recovery-Codes**
 *   (nur SHA-256-Hashes in der DB, Klartext einmalige Anzeige).
 * - **Verifikation** timing-sicher mit ±1 Zeitfenster und **Replay-Schutz**
 *   (derselbe Zeitschritt wird je Benutzer nicht zweimal akzeptiert).
 * - **SSO unberührt**: MFA gilt für die lokale Passwort-Anmeldung; bei
 *   Föderation setzt der IdP die MFA-Policy durch.
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
        // Kurzlebiger Zustand "zuletzt akzeptierter Zeitschritt" (Replay-Schutz);
        // _app_ reicht (TTL >> 2 Zeitschritte à 30 s).
        $this->replay = $replay ?? new CacheStore('_app_');
    }

    private function conn(): \Cake\Database\Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /** Ist MFA für diesen Benutzer aktiv? */
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

    /** Ist MFA für lokale Logins erzwungen (Betreiber-Setting)? */
    public function required(): bool
    {
        try {
            return (bool)(new SettingsManager())->get('core', 'security.mfa.required', false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Schließt das Enrollment ab: prüft den Bestätigungs-Code gegen das (noch
     * unpersistierte) Secret und aktiviert MFA. Gibt bei Erfolg die
     * **Klartext-Recovery-Codes** zurück (einmalige Anzeige), sonst null.
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
                // Gehasht wird die NORMALISIERTE Form (ohne Bindestrich/Spaces,
                // uppercase) — identisch zur Normalisierung beim Einlösen.
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
     * Prüft einen TOTP-Code (mit Replay-Schutz) ODER einen Recovery-Code
     * (einmal verwendbar, atomar entwertet).
     */
    public function verify(string $userId, string $code): bool
    {
        if (!Uuid::isValid($userId)) {
            return false;
        }
        $code = trim($code);

        // Recovery-Code? (Format-Heuristik: nicht 6-stellig numerisch.)
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
            return false; // Schlüssel falsch/Manipulation -> fail-closed
        }

        $step = Totp::verify($secret, $code);
        if ($step === null) {
            return false;
        }
        // Replay-Schutz: denselben (oder einen älteren) Zeitschritt nicht erneut
        // akzeptieren — ein mitgelesener Code ist damit nicht wiederverwendbar.
        $key = 'mfa.last_step.' . $userId;
        $last = (int)$this->replay->get($key, 0);
        if ($step <= $last) {
            return false;
        }
        $this->replay->set($key, $step);

        return true;
    }

    /** Deaktiviert MFA (Aufrufer hat den Benutzer bereits re-authentifiziert). */
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

    /** Anzahl noch unverbrauchter Recovery-Codes. */
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

    /** Löst einen Recovery-Code atomar ein (einmal verwendbar). */
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

    /** @return list<string> 8 Codes à 10 Zeichen (Crockford-ähnlich, gut abtippbar). */
    private function generateRecoveryCodes(): array
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTVWXYZ23456789'; // ohne I/L/O/0/1/U (Verwechslung)
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
