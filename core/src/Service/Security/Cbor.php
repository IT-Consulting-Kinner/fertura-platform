<?php
declare(strict_types=1);

namespace App\Service\Security;

use RuntimeException;

/**
 * Minimaler CBOR-Codec (RFC 8949) für die WebAuthn-Strukturen — bewusst
 * abhängigkeitsfrei (wie {@see Totp}). Unterstützt genau die von
 * attestationObject/COSE-Keys benötigte Teilmenge: unsigned/negative Integer,
 * Byte-/Text-Strings, Arrays und Maps (definite length). Der Encoder dient den
 * Tests (Konstruktion echter Attestation-Objekte ohne Browser).
 */
final class Cbor
{
    /**
     * Dekodiert EIN CBOR-Item ab `$offset`; `$offset` zeigt danach hinter das Item.
     */
    public static function decode(string $bytes, int &$offset = 0): mixed
    {
        if ($offset >= strlen($bytes)) {
            throw new RuntimeException('CBOR: unerwartetes Ende.');
        }
        $initial = ord($bytes[$offset++]);
        $major = $initial >> 5;
        $info = $initial & 0x1F;
        $value = self::readLength($bytes, $offset, $info);

        switch ($major) {
            case 0: // unsigned int
                return $value;
            case 1: // negative int: -1 - n
                return -1 - $value;
            case 2: // byte string
            case 3: // text string
                $s = substr($bytes, $offset, $value);
                if (strlen($s) !== $value) {
                    throw new RuntimeException('CBOR: String über Datenende.');
                }
                $offset += $value;

                return $s;
            case 4: // array
                $out = [];
                for ($i = 0; $i < $value; $i++) {
                    $out[] = self::decode($bytes, $offset);
                }

                return $out;
            case 5: // map
                $out = [];
                for ($i = 0; $i < $value; $i++) {
                    $key = self::decode($bytes, $offset);
                    if (!is_int($key) && !is_string($key)) {
                        throw new RuntimeException('CBOR: nicht unterstützter Map-Schlüssel.');
                    }
                    $out[$key] = self::decode($bytes, $offset);
                }

                return $out;
            default:
                throw new RuntimeException("CBOR: Major-Typ $major nicht unterstützt.");
        }
    }

    private static function readLength(string $bytes, int &$offset, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $lenBytes = match ($info) {
            24 => 1, 25 => 2, 26 => 4, 27 => 8,
            default => throw new RuntimeException('CBOR: indefinite length nicht unterstützt.'),
        };
        $raw = substr($bytes, $offset, $lenBytes);
        if (strlen($raw) !== $lenBytes) {
            throw new RuntimeException('CBOR: Länge über Datenende.');
        }
        $offset += $lenBytes;
        $value = 0;
        foreach (str_split($raw) as $b) {
            $value = ($value << 8) | ord($b);
        }

        return $value;
    }

    /** Kodiert int|string(byte)|array (Liste oder Map mit int/string-Keys). */
    public static function encode(mixed $value): string
    {
        if (is_int($value)) {
            return $value >= 0
                ? self::head(0, $value)
                : self::head(1, -1 - $value);
        }
        if (is_string($value)) {
            return self::head(2, strlen($value)) . $value; // immer Byte-String
        }
        if (is_array($value)) {
            $isList = array_is_list($value);
            $out = self::head($isList ? 4 : 5, count($value));
            foreach ($value as $k => $v) {
                if (!$isList) {
                    $out .= is_int($k) ? self::encode($k) : self::head(3, strlen($k)) . $k;
                }
                $out .= self::encode($v);
            }

            return $out;
        }
        throw new RuntimeException('CBOR-Encode: Typ nicht unterstützt.');
    }

    private static function head(int $major, int $value): string
    {
        $m = $major << 5;
        if ($value < 24) {
            return chr($m | $value);
        }
        if ($value < 256) {
            return chr($m | 24) . chr($value);
        }
        if ($value < 65536) {
            return chr($m | 25) . pack('n', $value);
        }

        return chr($m | 26) . pack('N', $value);
    }
}
