<?php
declare(strict_types=1);

namespace App\Service\Security;

/**
 * Verifiziert die Vertrauenskette Root -> Publisher (Kap. 24.9.2).
 *
 * Ein Publisher-Schlüssel ist nur vertrauenswürdig, wenn ein aktiver,
 * nicht-widerrufener Root-Schlüssel sein Zertifikat — die kanonische Aussage
 * über (key_id, public_key, publisher) — signiert hat. Diese Signatur
 * (`key_signature`) wird vom Betreiber-Werkzeug (`mp_tool sign-key`) erzeugt und
 * je Anker persistiert, sodass sie jederzeit nachprüfbar ist.
 */
class TrustChain
{
    private Signer $signer;
    private TrustStore $trust;

    public function __construct(?Signer $signer = null, ?TrustStore $trust = null)
    {
        $this->signer = $signer ?? new Signer();
        $this->trust = $trust ?? new TrustStore();
    }

    /**
     * Kanonische, signierbare Aussage über die Identität eines Publisher-
     * Schlüssels. Stabile Feldreihenfolge -> deterministisch.
     */
    public static function keyStatement(string $keyId, string $publicKey, ?string $publisher): string
    {
        return (string)json_encode(
            ['key_id' => $keyId, 'public_key' => $publicKey, 'publisher' => $publisher],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Prüft, ob ein Publisher-Zertifikat von einem aktiven Root signiert wurde.
     *
     * @param array{key_id?: mixed, public_key?: mixed, publisher?: mixed, signed_by?: mixed, key_signature?: mixed} $cert
     * @return array{ok: bool, reason?: string}
     */
    public function verifyPublisherCert(array $cert): array
    {
        $keyId = (string)($cert['key_id'] ?? '');
        $publicKey = (string)($cert['public_key'] ?? '');
        $publisher = isset($cert['publisher']) ? (string)$cert['publisher'] : null;
        $signedBy = (string)($cert['signed_by'] ?? '');
        $keySignature = (string)($cert['key_signature'] ?? '');

        if ($keyId === '' || $publicKey === '') {
            return ['ok' => false, 'reason' => 'Zertifikat unvollständig (key_id/public_key).'];
        }
        if ($signedBy === '' || $keySignature === '') {
            return ['ok' => false, 'reason' => 'Publisher-Anker ohne Root-Signatur (signed_by/key_signature).'];
        }
        if ($this->trust->isRevoked($signedBy)) {
            return ['ok' => false, 'reason' => "Signierender Root widerrufen: $signedBy"];
        }

        $root = $this->trust->getAnchor($signedBy);
        if ($root === null) {
            return ['ok' => false, 'reason' => "Unbekannter/inaktiver Root: $signedBy"];
        }
        if (($root['key_type'] ?? '') !== 'root') {
            return ['ok' => false, 'reason' => "Signierender Anker ist kein Root: $signedBy"];
        }

        $statement = self::keyStatement($keyId, $publicKey, $publisher);
        if (!$this->signer->verify($statement, $keySignature, (string)$root['public_key'])) {
            return ['ok' => false, 'reason' => 'Root-Signatur über das Publisher-Zertifikat ungültig.'];
        }

        return ['ok' => true];
    }
}
