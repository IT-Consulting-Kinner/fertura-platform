<?php
declare(strict_types=1);

namespace App\Service\Security;

/**
 * Prüft die Signatur eines Modulpakets (Verzeichnis) gegen einen aktiven,
 * nicht-widerrufenen Vertrauensanker — VOR dem Entpacken/Installieren
 * (Kap. 24.9.1/24.9.2).
 *
 * Paket-Signatur: `signature.json` = {"key_id": "...", "signature": "<base64>"}.
 * Signiert wird der Paket-Digest (SHA-256 über alle Dateien außer signature.json),
 * sodass jede Manipulation an Manifest ODER Code erkannt wird.
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
     * Deterministischer Paket-Digest über alle Dateien (außer signature.json).
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
     * Prüft die Paketsignatur. Wirft PackageVerificationException bei Fehler.
     *
     * @return array{key_id: string} Schlüssel-ID der gültigen Signatur.
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
        // Gültigkeitsfenster des Ankers durchsetzen (Kap. 24.9.2).
        $validity = TrustStore::validity($anchor);
        if (!$validity['ok']) {
            throw new PackageVerificationException("Vertrauensanker $keyId: " . $validity['reason']);
        }
        // Publisher-Bindung (Kap. 24.9.2): Publisher-Schlüssel müssen zum
        // Manifest-Publisher passen.
        if ($anchor['key_type'] === 'publisher'
            && $manifestPublisher !== null
            && $anchor['publisher'] !== $manifestPublisher) {
            throw new PackageVerificationException('Publisher des Schlüssels passt nicht zum Manifest.');
        }

        // Vertrauenskette (Kap. 24.9.2): Publisher-Anker müssen weiterhin von
        // einem aktiven, nicht-widerrufenen Root signiert sein. So entzieht ein
        // Root-Widerruf nachträglich allen darunter signierten Paketen das
        // Vertrauen (Defense-in-Depth über die Insert-Zeit-Prüfung hinaus).
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
