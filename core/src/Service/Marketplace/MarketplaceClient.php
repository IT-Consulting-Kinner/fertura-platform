<?php
declare(strict_types=1);

namespace App\Service\Marketplace;

use App\Audit\AuditLogger;
use App\Service\Security\Signer;
use App\Service\Security\TrustStore;
use App\Service\Settings\SettingsManager;
use Cake\Http\Client;
use RuntimeException;
use Throwable;

/**
 * Marketplace-Kommunikation (Kap. 28.4–28.6): Abruf von Metadaten, Sperrliste
 * (CRL) und aktualisierten Vertrauensankern über einen signierten Kanal.
 *
 * Reiner Metadatenabruf hat keine Systemwirkung; nur die signiert verifizierten
 * CRL/Anker werden angewendet (Entscheidung 139).
 */
class MarketplaceClient
{
    private Signer $signer;
    private TrustStore $trust;
    private SettingsManager $settings;
    private AuditLogger $audit;
    private Client $http;

    public function __construct(
        ?Signer $signer = null,
        ?TrustStore $trust = null,
        ?SettingsManager $settings = null,
        ?AuditLogger $audit = null,
        ?Client $http = null,
    ) {
        $this->signer = $signer ?? new Signer();
        $this->trust = $trust ?? new TrustStore();
        $this->settings = $settings ?? new SettingsManager();
        $this->audit = $audit ?? new AuditLogger();
        $this->http = $http ?? new Client();
    }

    public static function canonical(array $payload): string
    {
        return (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function baseUrl(): string
    {
        $url = (string)$this->settings->get('core', 'marketplace.base_url', '');
        if ($url === '') {
            throw new RuntimeException('Kein Marketplace konfiguriert (core.marketplace.base_url).');
        }

        return rtrim($url, '/');
    }

    private function fetch(string $path): ?array
    {
        try {
            $response = $this->http->get($this->baseUrl() . '/' . ltrim($path, '/'));
        } catch (Throwable) {
            return null;
        }
        if (!$response->isOk()) {
            return null;
        }
        $data = json_decode($response->getStringBody(), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Verifiziert ein signiertes Dokument {payload, key_id, signature} gegen
     * einen aktiven, nicht-widerrufenen Vertrauensanker.
     *
     * @return array<string, mixed>|null Payload bei Erfolg, sonst null.
     */
    private function verifySigned(?array $doc): ?array
    {
        if (!is_array($doc) || !isset($doc['payload'], $doc['key_id'], $doc['signature'])) {
            return null;
        }
        $keyId = (string)$doc['key_id'];
        if ($this->trust->isRevoked($keyId)) {
            return null;
        }
        $anchor = $this->trust->getAnchor($keyId);
        if ($anchor === null) {
            return null;
        }
        if (!$this->signer->verify(self::canonical($doc['payload']), (string)$doc['signature'], (string)$anchor['public_key'])) {
            return null;
        }

        return $doc['payload'];
    }

    /**
     * Holt CRL + Vertrauensanker und wendet sie an.
     *
     * @return array{revoked: int, anchors: int}
     */
    public function sync(): array
    {
        $revoked = 0;
        $anchors = 0;

        $crl = $this->verifySigned($this->fetch('crl.json'));
        if ($crl !== null) {
            foreach ($crl['revoked'] ?? [] as $entry) {
                $this->trust->revokeKey((string)$entry['key_id'], $entry['reason'] ?? null, 'crl');
                $revoked++;
            }
        }

        $anchorDoc = $this->verifySigned($this->fetch('anchors.json'));
        if ($anchorDoc !== null) {
            $chain = new \App\Service\Security\TrustChain($this->signer, $this->trust);
            foreach ($anchorDoc['anchors'] ?? [] as $a) {
                $type = (string)($a['type'] ?? 'publisher');
                // Publisher-Anker nur mit gültiger Root-Signatur übernehmen
                // (Kette Root -> Publisher, Kap. 24.9.2) – Defense-in-Depth über
                // die Dokument-Signatur hinaus.
                if ($type === 'publisher') {
                    $check = $chain->verifyPublisherCert($a);
                    if (!$check['ok']) {
                        $this->audit->log('trust_anchor.rejected', 'trust_anchor', (string)($a['key_id'] ?? ''), [
                            'newValue' => ['reason' => $check['reason'] ?? 'Kette ungültig'],
                        ]);
                        continue;
                    }
                }
                $this->trust->addAnchor(
                    (string)$a['key_id'],
                    (string)$a['public_key'],
                    $type,
                    $a['publisher'] ?? null,
                    $a['signed_by'] ?? null,
                    $a['key_signature'] ?? null,
                );
                $anchors++;
            }
        }

        $this->audit->log('marketplace.sync', 'marketplace', 'marketplace', [
            'newValue' => ['revoked' => $revoked, 'anchors' => $anchors],
        ]);

        return ['revoked' => $revoked, 'anchors' => $anchors];
    }

    /** @return array<string, mixed>|null Verifizierte Metadaten. */
    public function metadata(): ?array
    {
        return $this->verifySigned($this->fetch('metadata.json'));
    }
}
