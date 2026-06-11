<?php
declare(strict_types=1);

namespace App\Service\Security;

/**
 * TOTP (RFC 6238) auf HOTP-Basis (RFC 4226) — bewusst abhängigkeitsfrei
 * (Supply-Chain schlank halten): HMAC-SHA1, 6 Ziffern, 30-s-Schritt, wie von
 * allen gängigen Authenticator-Apps (Google/Microsoft/Aegis/1Password) erwartet.
 *
 * Verifikation mit ±1 Zeitfenster (Uhren-Drift) und `hash_equals`
 * (timing-sicher). Replay-Schutz auf Code-Ebene (denselben Code nicht zweimal
 * akzeptieren) übernimmt der Aufrufer ({@see MfaService}).
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    private const SECRET_BYTES = 20; // 160 Bit, RFC-4226-Empfehlung

    private const B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Neues Secret, Base32-kodiert (Eingabeformat der Authenticator-Apps). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /** Aktueller Code für ein Base32-Secret (für Anzeige/Tests). */
    public static function code(string $base32Secret, ?int $timestamp = null): string
    {
        $counter = intdiv($timestamp ?? time(), self::PERIOD);

        return self::hotp(self::base32Decode($base32Secret), $counter);
    }

    /**
     * Prüft einen Code timing-sicher mit ±`$window` Zeitschritten Toleranz.
     * Gibt bei Erfolg den **Zeitschritt** des Treffers zurück (für den
     * Replay-Schutz des Aufrufers), sonst null.
     */
    public static function verify(string $base32Secret, string $code, int $window = 1, ?int $timestamp = null): ?int
    {
        $code = trim($code);
        if (!preg_match('/^[0-9]{' . self::DIGITS . '}\z/', $code)) {
            return null;
        }
        $key = self::base32Decode($base32Secret);
        $step = intdiv($timestamp ?? time(), self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::hotp($key, $step + $i), $code)) {
                return $step + $i;
            }
        }

        return null;
    }

    /** otpauth://-URI für die Einrichtung (manuell oder als QR-Inhalt). */
    public static function provisioningUri(string $base32Secret, string $accountLabel, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($accountLabel)
            . '?secret=' . $base32Secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /** HOTP (RFC 4226): HMAC-SHA1 + Dynamic Truncation. */
    private static function hotp(string $key, int $counter): string
    {
        $binCounter = pack('J', $counter); // 64 Bit big-endian
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string)($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $out = '';
        $bits = 0;
        $value = 0;
        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $out .= self::B32_ALPHABET[($value >> ($bits - 5)) & 31];
                $bits -= 5;
            }
        }
        if ($bits > 0) {
            $out .= self::B32_ALPHABET[($value << (5 - $bits)) & 31];
        }

        return $out;
    }

    private static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(str_replace([' ', '='], '', $encoded));
        $out = '';
        $bits = 0;
        $value = 0;
        foreach (str_split($encoded) as $char) {
            $pos = strpos(self::B32_ALPHABET, $char);
            if ($pos === false) {
                continue; // fremde Zeichen (Bindestriche etc.) überspringen
            }
            $value = ($value << 5) | $pos;
            $bits += 5;
            if ($bits >= 8) {
                $out .= chr(($value >> ($bits - 8)) & 0xFF);
                $bits -= 8;
            }
        }

        return $out;
    }
}
