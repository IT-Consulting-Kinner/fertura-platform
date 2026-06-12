<?php
declare(strict_types=1);

namespace App\Service\Security;

/**
 * Verifies the signature of a module package (directory) against an active,
 * non-revoked trust anchor — BEFORE unpacking/installing
 * (ch. 24.9.1/24.9.2).
 *
 * Package signature: `signature.json` = {"key_id": "...", "signature": "<base64>"}.
 * What is signed is the package digest (SHA-256 over all files except signature.json),
 * so any tampering with the manifest OR the code is detected.
 */
class PackageVerifier
{
    public const SIGNATURE_FILE = 'signature.json';

    private Signer $signer;
    private TrustStore $trust;

    public function __construct(?Signer $signer = null, ?TrustStore $trust = null)
    {
        $this->signer = $signer ?? new Signer();
        $this->trust = $trust ?? new TrustStore();
    }

    /**
     * Deterministic package digest over all files (except signature.json).
     */
    public function packageDigest(string $dir): string
    {
        $dir = rtrim($dir, '/');
        $entries = [];
        $this->collect($dir, $dir, $entries);
        sort($entries);

        return hash('sha256', implode("\n", $entries));
    }

    /**
     * @param list<string> $entries
     */
    private function collect(string $base, string $current, array &$entries): void
    {
        foreach (scandir($current) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $current . '/' . $item;
            if (is_dir($path)) {
                $this->collect($base, $path, $entries);
                continue;
            }
            $rel = ltrim(substr($path, strlen($base)), '/');
            if ($rel === self::SIGNATURE_FILE) {
                continue;
            }
            $entries[] = $rel . '=' . hash_file('sha256', $path);
        }
    }

    /**
     * Verifies the package signature. Throws PackageVerificationException on error.
     *
     * @return array{key_id: string} Key ID of the valid signature.
     */
    public function verify(string $dir, ?string $manifestPublisher): array
    {
        $sigFile = rtrim($dir, '/') . '/' . self::SIGNATURE_FILE;
        if (!is_file($sigFile)) {
            throw new PackageVerificationException('Paket ist nicht signiert (signature.json fehlt).');
        }
        $sig = json_decode((string)file_get_contents($sigFile), true);
        if (!is_array($sig) || empty($sig['key_id']) || empty($sig['signature'])) {
            throw new PackageVerificationException('Ungültige signature.json.');
        }
        $keyId = (string)$sig['key_id'];

        if ($this->trust->isRevoked($keyId)) {
            throw new PackageVerificationException("Signaturschlüssel widerrufen: $keyId");
        }
        $anchor = $this->trust->getAnchor($keyId);
        if ($anchor === null) {
            throw new PackageVerificationException("Unbekannter/inaktiver Vertrauensanker: $keyId");
        }
        // Enforce the anchor's validity window (ch. 24.9.2).
        $validity = TrustStore::validity($anchor);
        if (!$validity['ok']) {
            throw new PackageVerificationException("Vertrauensanker $keyId: " . $validity['reason']);
        }
        // Publisher binding (ch. 24.9.2): publisher keys must match the
        // manifest publisher.
        if (
            $anchor['key_type'] === 'publisher'
            && $manifestPublisher !== null
            && $anchor['publisher'] !== $manifestPublisher
        ) {
            throw new PackageVerificationException('Publisher des Schlüssels passt nicht zum Manifest.');
        }

        // Trust chain (ch. 24.9.2): publisher anchors must still be signed by an
        // active, non-revoked root. This way a root revocation retroactively
        // withdraws trust from all packages signed below it (defense-in-depth
        // beyond the insert-time check).
        if ($anchor['key_type'] === 'publisher') {
            $chain = (new TrustChain($this->signer, $this->trust))->verifyPublisherCert($anchor);
            if (!$chain['ok']) {
                throw new PackageVerificationException('Vertrauenskette ungültig: ' . ($chain['reason'] ?? 'unbekannt'));
            }
        }

        $digest = $this->packageDigest($dir);
        if (!$this->signer->verify($digest, (string)$sig['signature'], (string)$anchor['public_key'])) {
            throw new PackageVerificationException('Signatur ungültig (Paket manipuliert oder falscher Schlüssel).');
        }

        return ['key_id' => $keyId];
    }
}
