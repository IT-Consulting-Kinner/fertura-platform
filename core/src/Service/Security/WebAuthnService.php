<?php
declare(strict_types=1);

namespace App\Service\Security;

use App\Audit\AuditLogger;
use App\Infrastructure\Uuid;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use RuntimeException;

/**
 * Passkeys/WebAuthn as a **second factor** (stretch goal for E129) —
 * dependency-free (own CBOR codec, OpenSSL verification), deliberately narrow
 * in scope:
 *
 * - Attestation `none` (the browser default; device attestation is meaningless
 *   for 2FA without a vendor policy), algorithms **ES256** (-7) and **RS256**
 *   (-257) — covering platform authenticators (TouchID/Windows Hello/Android)
 *   and common security keys.
 * - Registration validates clientData (type/challenge/origin), rpIdHash and
 *   user presence; the **public key is stored as PEM** plus the sign counter.
 * - Assertion additionally verifies the **signature** over
 *   `authenticatorData || sha256(clientDataJSON)` and the **sign counter**
 *   (clone detection: a regressing counter is rejected, provided the
 *   authenticator counts at all).
 *
 * Passwordless first-factor login is deliberately out of scope (2FA scope).
 */
class WebAuthnService
{
    private const COSE_ES256 = -7;
    private const COSE_RS256 = -257;
    /** DER SPKI prefix for EC P-256 (ecPublicKey + prime256v1, 65-byte point). */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    private AuditLogger $audit;

    public function __construct(?AuditLogger $audit = null)
    {
        $this->audit = $audit ?? new AuditLogger();
    }

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /** Random challenge (base64url, for session storage + options JSON). */
    public static function challenge(): string
    {
        return self::b64uEncode(random_bytes(32));
    }

    /**
     * Options for `navigator.credentials.create()` (registration).
     *
     * @return array<string,mixed>
     */
    public function registrationOptions(string $userId, string $username, string $rpId, string $challenge): array
    {
        return [
            'challenge' => $challenge,
            'rp' => ['id' => $rpId, 'name' => MfaService::ISSUER],
            'user' => ['id' => self::b64uEncode($userId), 'name' => $username, 'displayName' => $username],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => self::COSE_ES256],
                ['type' => 'public-key', 'alg' => self::COSE_RS256],
            ],
            'timeout' => 60000,
            'attestation' => 'none',
            'authenticatorSelection' => ['userVerification' => 'preferred', 'residentKey' => 'discouraged'],
            'excludeCredentials' => array_map(
                static fn(array $c) => ['type' => 'public-key', 'id' => $c['credential_id']],
                $this->credentials($userId),
            ),
        ];
    }

    /**
     * Options for `navigator.credentials.get()` (login assertion).
     *
     * @return array<string,mixed>
     */
    public function assertionOptions(string $userId, string $rpId, string $challenge): array
    {
        return [
            'challenge' => $challenge,
            'rpId' => $rpId,
            'timeout' => 60000,
            'userVerification' => 'preferred',
            'allowCredentials' => array_map(
                static fn(array $c) => ['type' => 'public-key', 'id' => $c['credential_id']],
                $this->credentials($userId),
            ),
        ];
    }

    /**
     * Completes registration (the response from `credentials.create()`).
     * Throws on ANY deviation (fail-closed).
     */
    public function register(
        string $userId,
        string $clientDataJsonB64u,
        string $attestationObjectB64u,
        string $expectedChallenge,
        string $rpId,
        ?string $label = null,
    ): void {
        if (!Uuid::isValid($userId)) {
            throw new RuntimeException('Unbekannter Benutzer.');
        }
        $clientData = self::b64uDecode($clientDataJsonB64u);
        $this->assertClientData($clientData, 'webauthn.create', $expectedChallenge, $rpId);

        $offset = 0;
        $att = Cbor::decode(self::b64uDecode($attestationObjectB64u), $offset);
        if (!is_array($att) || !isset($att['authData']) || !is_string($att['authData'])) {
            throw new RuntimeException('WebAuthn: attestationObject ungültig.');
        }
        $authData = $att['authData'];
        $this->assertAuthData($authData, $rpId, true);

        // Attested credential data: aaguid(16) | credIdLen(2) | credId | COSE key.
        $credIdLen = (ord($authData[53]) << 8) | ord($authData[54]);
        $credentialId = substr($authData, 55, $credIdLen);
        if (strlen($credentialId) !== $credIdLen || $credIdLen < 1) {
            throw new RuntimeException('WebAuthn: Credential-ID ungültig.');
        }
        $keyOffset = 55 + $credIdLen;
        $cose = Cbor::decode($authData, $keyOffset);
        if (!is_array($cose)) {
            throw new RuntimeException('WebAuthn: COSE-Key ungültig.');
        }
        $pem = $this->coseToPem($cose);
        $signCount = self::signCount($authData);

        $this->conn()->execute(
            'INSERT INTO user_webauthn_credentials (user_id, credential_id, public_key_pem, sign_count, label) '
            . 'VALUES (:u, :c, :p, :n, :l)',
            [
                'u' => $userId,
                'c' => self::b64uEncode($credentialId),
                'p' => $pem,
                'n' => $signCount,
                'l' => $label !== null && trim($label) !== '' ? trim($label) : null,
            ],
        );
        $this->audit->log('mfa.passkey_register', 'user', $userId, ['newValue' => ['label' => $label]]);
    }

    /**
     * Verifies a login assertion (the response from `credentials.get()`).
     * Returns true on a valid signature + counter.
     */
    public function verifyAssertion(
        string $userId,
        string $credentialIdB64u,
        string $clientDataJsonB64u,
        string $authenticatorDataB64u,
        string $signatureB64u,
        string $expectedChallenge,
        string $rpId,
    ): bool {
        if (!Uuid::isValid($userId)) {
            return false;
        }
        $row = $this->conn()->execute(
            'SELECT id, public_key_pem, sign_count FROM user_webauthn_credentials '
            . 'WHERE user_id = :u AND credential_id = :c',
            ['u' => $userId, 'c' => $credentialIdB64u],
        )->fetch('assoc');
        if ($row === false) {
            return false;
        }

        try {
            $clientData = self::b64uDecode($clientDataJsonB64u);
            $this->assertClientData($clientData, 'webauthn.get', $expectedChallenge, $rpId);
            $authData = self::b64uDecode($authenticatorDataB64u);
            $this->assertAuthData($authData, $rpId, false);
        } catch (RuntimeException) {
            return false;
        }

        $signed = $authData . hash('sha256', $clientData, true);
        $ok = openssl_verify(
            $signed,
            self::b64uDecode($signatureB64u),
            (string)$row['public_key_pem'],
            OPENSSL_ALGO_SHA256,
        ) === 1;
        if (!$ok) {
            return false;
        }

        // Sign counter (clone detection): if the authenticator counts, the value
        // must strictly increase; 0 = the authenticator does not count (e.g. some
        // platform authenticators) -> the check is skipped per the specification.
        $newCount = self::signCount($authData);
        $oldCount = (int)$row['sign_count'];
        if ($newCount !== 0 && $newCount <= $oldCount) {
            $this->audit->log('mfa.passkey_counter_anomaly', 'user', $userId, [
                'newValue' => ['stored' => $oldCount, 'asserted' => $newCount],
            ]);

            return false;
        }
        $this->conn()->execute(
            'UPDATE user_webauthn_credentials SET sign_count = :n, last_used_at = now() WHERE id = :id',
            ['n' => $newCount, 'id' => (string)$row['id']],
        );

        return true;
    }

    /** @return list<array{id:string,credential_id:string,label:?string,created_at:string,last_used_at:?string}> */
    public function credentials(string $userId): array
    {
        if (!Uuid::isValid($userId)) {
            return [];
        }
        /** @var list<array{id:string,credential_id:string,label:?string,created_at:string,last_used_at:?string}> $rows */
        $rows = $this->conn()->execute(
            'SELECT id, credential_id, label, created_at, last_used_at FROM user_webauthn_credentials '
            . 'WHERE user_id = :u ORDER BY created_at',
            ['u' => $userId],
        )->fetchAll('assoc');

        return array_values($rows);
    }

    public function hasCredentials(string $userId): bool
    {
        return $this->credentials($userId) !== [];
    }

    public function delete(string $userId, string $credentialRowId): bool
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($credentialRowId)) {
            return false;
        }
        $n = $this->conn()->execute(
            'DELETE FROM user_webauthn_credentials WHERE id = :id AND user_id = :u',
            ['id' => $credentialRowId, 'u' => $userId],
        )->rowCount();
        if ($n > 0) {
            $this->audit->log('mfa.passkey_delete', 'user', $userId, []);
        }

        return $n > 0;
    }

    // ---- internal -------------------------------------------------------------

    /** clientDataJSON: type, challenge (base64url) and origin host must match. */
    private function assertClientData(string $clientDataJson, string $expectedType, string $expectedChallenge, string $rpId): void
    {
        $data = json_decode($clientDataJson, true);
        if (!is_array($data)) {
            throw new RuntimeException('WebAuthn: clientDataJSON ungültig.');
        }
        if (($data['type'] ?? '') !== $expectedType) {
            throw new RuntimeException('WebAuthn: falscher clientData-Typ.');
        }
        if (!hash_equals($expectedChallenge, (string)($data['challenge'] ?? ''))) {
            throw new RuntimeException('WebAuthn: Challenge stimmt nicht überein.');
        }
        $originHost = (string)parse_url((string)($data['origin'] ?? ''), PHP_URL_HOST);
        if ($originHost === '' || strcasecmp($originHost, $rpId) !== 0) {
            throw new RuntimeException('WebAuthn: Origin passt nicht zur Relying Party.');
        }
    }

    /** authenticatorData: rpIdHash + user presence (+ attested data on registration). */
    private function assertAuthData(string $authData, string $rpId, bool $requireAttestedData): void
    {
        if (strlen($authData) < 37) {
            throw new RuntimeException('WebAuthn: authenticatorData zu kurz.');
        }
        if (!hash_equals(hash('sha256', $rpId, true), substr($authData, 0, 32))) {
            throw new RuntimeException('WebAuthn: rpIdHash stimmt nicht.');
        }
        $flags = ord($authData[32]);
        if (($flags & 0x01) === 0) { // UP: user presence
            throw new RuntimeException('WebAuthn: User-Presence fehlt.');
        }
        if ($requireAttestedData && (($flags & 0x40) === 0 || strlen($authData) < 56)) { // AT
            throw new RuntimeException('WebAuthn: Attested Credential Data fehlt.');
        }
    }

    private static function signCount(string $authData): int
    {
        $parts = unpack('N', substr($authData, 33, 4));

        return $parts === false ? 0 : (int)$parts[1];
    }

    /**
     * COSE key -> PEM (SubjectPublicKeyInfo) for openssl_verify.
     *
     * @param array<int|string,mixed> $cose
     */
    private function coseToPem(array $cose): string
    {
        $kty = (int)($cose[1] ?? 0);
        $alg = (int)($cose[3] ?? 0);

        if ($kty === 2 && $alg === self::COSE_ES256) { // EC2, P-256
            $x = (string)($cose[-2] ?? '');
            $y = (string)($cose[-3] ?? '');
            if (strlen($x) !== 32 || strlen($y) !== 32 || (int)($cose[-1] ?? 0) !== 1) {
                throw new RuntimeException('WebAuthn: EC-Key ungültig (P-256 erwartet).');
            }
            $der = hex2bin(self::P256_SPKI_PREFIX) . "\x04" . $x . $y;

            return self::derToPem($der);
        }
        if ($kty === 3 && $alg === self::COSE_RS256) { // RSA
            $n = (string)($cose[-1] ?? '');
            $e = (string)($cose[-2] ?? '');
            if ($n === '' || $e === '') {
                throw new RuntimeException('WebAuthn: RSA-Key ungültig.');
            }
            $rsaSeq = self::derSequence(self::derUint($n) . self::derUint($e));
            $algSeq = self::derSequence((string)hex2bin('06092a864886f70d0101010500')); // rsaEncryption + NULL
            $der = self::derSequence($algSeq . self::derBitString($rsaSeq));

            return self::derToPem($der);
        }
        throw new RuntimeException('WebAuthn: Algorithmus nicht unterstützt (ES256/RS256).');
    }

    private static function derToPem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = ltrim(pack('N', $len), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $content): string
    {
        return "\x30" . self::derLength(strlen($content)) . $content;
    }

    private static function derUint(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes; // sign byte (a DER integer is signed)
        }

        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derBitString(string $content): string
    {
        return "\x03" . self::derLength(strlen($content) + 1) . "\x00" . $content;
    }

    public static function b64uEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $encoded): string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('WebAuthn: base64url ungültig.');
        }

        return $decoded;
    }
}
