<?php
declare(strict_types=1);

namespace App\Service\Security;

/**
 * TOTP (RFC 6238) built on HOTP (RFC 4226) — deliberately dependency-free
 * (to keep the supply chain lean): HMAC-SHA1, 6 digits, 30-second step, as
 * expected by all common authenticator apps (Google/Microsoft/Aegis/1Password).
 *
 * Verification uses a ±1 time window (clock drift) and `hash_equals`
 * (timing-safe). Code-level replay protection (not accepting the same code
 * twice) is the caller's responsibility ({@see MfaService}).
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    private const SECRET_BYTES = 20; // 160 bits, RFC 4226 recommendation

    private const B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** New secret, Base32-encoded (the input format of authenticator apps). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /** Current code for a Base32 secret (for display/tests). */
    public static function code(string $base32Secret, ?int $timestamp = null): string
    {
        $counter = intdiv($timestamp ?? time(), self::PERIOD);

        return self::hotp(self::base32Decode($base32Secret), $counter);
    }

    /**
     * Verifies a code timing-safely with a tolerance of ±`$window` time steps.
     * On success returns the **time step** of the match (for the caller's replay
     * protection), otherwise null.
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

    /** otpauth:// URI for setup (manual entry or as QR content). */
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
        $binCounter = pack('J', $counter); // 64-bit big-endian
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
                continue; // skip foreign characters (hyphens etc.)
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
