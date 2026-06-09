<?php
declare(strict_types=1);

namespace App\Service\Module;

/**
 * Pro-Aufruf-Capability-Token für die Out-of-Process-RPC (Kap. 23.16.2).
 *
 * Härtet die zuvor rein kanal-basierte Authentifizierung (ein statisches, bei
 * jedem Aufruf mitgeschicktes Host-Token) zu einer **aufruf-gebundenen**:
 *
 *  - Das gemeinsame Geheimnis (`MODULE_RPC_TOKEN`, 0600-Datei) wird **nur noch
 *    als HMAC-Schlüssel** genutzt und reist **nie** über den Socket. Ein
 *    Mitlauscher am Unix-Socket (gleiche UID, solange keine OS-Isolation) sieht
 *    nur Pro-Aufruf-MACs, nicht das wiederverwendbare Geheimnis.
 *  - Der MAC deckt die **gesamte kanonisierte Anfrage** ab (op/class/method/
 *    args/rls/contract/input) plus Nonce und Ablauf. Ein abgefangener MAC lässt
 *    sich daher **nicht** auf einen anderen Aufruf (andere Methode/Argumente/
 *    RLS-Kontext, z. B. `bypass=true`) ummünzen — Integrität inklusive.
 *  - **Zeitlich begrenzt** (`exp`) und **einmalig** (Nonce; der Host weist
 *    bereits gesehene Nonces ab) -> Replay ist eng beschränkt.
 *
 * Symmetrisch: {@see mint()} erzeugt die Auth-Felder (Core-Seite), {@see verify()}
 * prüft sie (Host-Seite). Beide Seiten nutzen dieselbe Kanonisierung.
 */
final class RpcCapabilityToken
{
    /** Gültigkeitsfenster eines Aufruf-Tokens (Sekunden). RPC ist lokal/sofort. */
    public const TTL_SECONDS = 60;

    /** Nicht-signierte Hilfsfelder der Anfrage (aus der Kanonisierung ausgenommen). */
    private const AUTH_FIELDS = ['token', 'nonce', 'exp', 'cap'];

    /**
     * Erzeugt die Auth-Felder für eine Anfrage (Core-Seite).
     *
     * @param array<string, mixed> $req Die Anfrage OHNE Auth-Felder.
     * @return array{nonce:string, exp:int, cap:string}
     */
    public static function mint(string $secret, array $req, ?int $now = null): array
    {
        $now ??= time();
        $nonce = bin2hex(random_bytes(16));
        $exp = $now + self::TTL_SECONDS;

        return ['nonce' => $nonce, 'exp' => $exp, 'cap' => self::sign($secret, $req, $nonce, $exp)];
    }

    /**
     * Prüft die Auth-Felder einer Anfrage (Host-Seite): MAC korrekt, nicht
     * abgelaufen, nicht unplausibel weit in der Zukunft. Die **Einmaligkeit**
     * (Nonce-Replay) prüft der Aufrufer zustandsbehaftet (Host-Schleife).
     *
     * @param array<string, mixed> $req
     */
    public static function verify(string $secret, array $req, ?int $now = null): bool
    {
        $now ??= time();
        $nonce = (string)($req['nonce'] ?? '');
        $exp = (int)($req['exp'] ?? 0);
        $cap = (string)($req['cap'] ?? '');
        if ($nonce === '' || $cap === '' || $exp <= 0) {
            return false;
        }
        // Abgelaufen oder unplausibel weit in der Zukunft (gegen Endlos-Tokens).
        if ($now > $exp || $exp > $now + self::TTL_SECONDS + 5) {
            return false;
        }

        return hash_equals(self::sign($secret, $req, $nonce, $exp), $cap);
    }

    /**
     * Deterministische Kanonisierung der Anfrage ohne Auth-Felder. Ein
     * JSON-Roundtrip stellt sicher, dass Core (vor dem Senden) und Host (nach
     * dem Empfangen/Decodieren) **dieselbe** Struktur signieren/prüfen.
     *
     * @param array<string, mixed> $req
     */
    public static function canonical(array $req): string
    {
        foreach (self::AUTH_FIELDS as $f) {
            unset($req[$f]);
        }
        /** @var array<string, mixed> $roundtrip */
        $roundtrip = json_decode((string)json_encode($req), true) ?? [];

        return (string)json_encode(self::normalize($roundtrip), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $req
     */
    private static function sign(string $secret, array $req, string $nonce, int $exp): string
    {
        return hash_hmac('sha256', self::canonical($req) . '|' . $nonce . '|' . $exp, $secret);
    }

    /** Assoziative Arrays nach Schlüssel sortieren; Listen in Reihenfolge belassen. */
    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::normalize($v);
        }
        if (!$isList) {
            ksort($out);
        }

        return $out;
    }
}
