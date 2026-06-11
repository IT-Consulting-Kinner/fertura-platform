<?php
declare(strict_types=1);

namespace App\Service\Module;

/**
 * Per-call capability token for the out-of-process RPC (ch. 23.16.2).
 *
 * Hardens the previously purely channel-based authentication (a static host
 * token sent on every call) into a **call-bound** one:
 *
 *  - The shared secret (`MODULE_RPC_TOKEN`, 0600 file) is **now used only as an
 *    HMAC key** and **never** travels over the socket. An eavesdropper on the
 *    Unix socket (same UID, as long as there is no OS isolation) sees only
 *    per-call MACs, not the reusable secret.
 *  - The MAC covers the **entire canonicalized request** (op/class/method/
 *    args/rls/contract/input) plus the nonce and expiry. An intercepted MAC can
 *    therefore **not** be repurposed for a different call (different method/
 *    arguments/RLS context, e.g. `bypass=true`) — integrity is included.
 *  - **Time-limited** (`exp`) and **single-use** (nonce; the host rejects
 *    already-seen nonces) -> replay is tightly bounded.
 *
 * Symmetric: {@see mint()} produces the auth fields (core side), {@see verify()}
 * checks them (host side). Both sides use the same canonicalization.
 *
 * **Threat model (honest).** The token authenticates the **inter-process
 * boundary** (core calls the host) and protects against other socket clients as
 * well as tampering/replay of an intercepted request. It does **not** constrain
 * the module code running inside the host itself: that code knows the secret
 * (its own 0600 file, same UID) and can call contributions in its own namespace
 * anyway. The module's actual sandbox is the restricted DB role, the sanitized
 * environment and the optional OS isolation (launcher prefix) — not this token.
 */
final class RpcCapabilityToken
{
    /** Validity window of a call token (seconds). RPC is local/instant. */
    public const TTL_SECONDS = 60;

    /** Unsigned helper fields of the request (excluded from canonicalization). */
    private const AUTH_FIELDS = ['token', 'nonce', 'exp', 'cap'];

    /**
     * Produces the auth fields for a request (core side).
     *
     * @param array<string, mixed> $req The request WITHOUT auth fields.
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
     * Checks the auth fields of a request (host side): MAC correct, not expired,
     * not implausibly far in the future. The **single-use** property (nonce
     * replay) is checked statefully by the caller (the host loop).
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
        // Expired or implausibly far in the future (guards against endless tokens).
        if ($now > $exp || $exp > $now + self::TTL_SECONDS + 5) {
            return false;
        }

        return hash_equals(self::sign($secret, $req, $nonce, $exp), $cap);
    }

    /**
     * Deterministic canonicalization of the request without the auth fields. A
     * JSON round-trip ensures that the core (before sending) and the host (after
     * receiving/decoding) sign/check the **same** structure.
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

    /** Sort associative arrays by key; leave lists in order. */
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
