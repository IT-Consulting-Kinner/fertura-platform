<?php
declare(strict_types=1);

namespace App\Service\Marketplace;

use App\Audit\AuditLogger;
use App\Service\Security\Signer;
use App\Service\Security\TrustChain;
use App\Service\Security\TrustStore;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Client;
use RuntimeException;
use Throwable;

/**
 * Marketplace communication (ch. 28.4–28.6): fetches metadata, the revocation
 * list (CRL) and updated trust anchors over a signed channel.
 *
 * A plain metadata fetch has no effect on the system; only the signature-
 * verified CRL/anchors are actually applied (Decision 139).
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
     * Verifies a signed document {payload, key_id, signature} against an active,
     * non-revoked trust anchor.
     *
     * @return array<string, mixed>|null The payload on success, null otherwise.
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
        // Enforce the anchor's validity window (as on the other verification
        // paths, ch. 24.9.2): an expired/not-yet-valid anchor must no longer be
        // able to sign CRL/anchor documents either.
        if (!TrustStore::validity($anchor)['ok']) {
            return null;
        }
        // Marketplace documents (crl/anchors/metadata) are ROOT-signed (operator
        // tooling, MpToolCommand). Publisher keys are operational and held by
        // third-party vendors — they sign packages/licenses, NEVER these documents.
        // Without this check a publisher key could sign a crafted anchors.json/crl
        // to inject a root anchor or revoke keys (peer-review F7). Require root.
        if ((string)($anchor['key_type'] ?? '') !== 'root') {
            return null;
        }
        if (!$this->signer->verify(self::canonical($doc['payload']), (string)$doc['signature'], (string)$anchor['public_key'])) {
            return null;
        }

        return $doc['payload'];
    }

    /**
     * Fetches the CRL + trust anchors and applies them.
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
            // Timestamp the successful CRL fetch (cache age / stale warning, ch. 24.9.2).
            $this->setMeta('last_crl_fetch_at', date('c'));
        }

        $anchorDoc = $this->verifySigned($this->fetch('anchors.json'));
        if ($anchorDoc !== null) {
            $chain = new TrustChain($this->signer, $this->trust);
            foreach ($anchorDoc['anchors'] ?? [] as $a) {
                $type = (string)($a['type'] ?? 'publisher');
                // Only PUBLISHER anchors may be added via a fetched document, and
                // each must chain to a trusted root (Root -> Publisher, ch. 24.9.2).
                // Root anchors are bootstrapped out-of-band and are NEVER installed
                // from a sync: a crafted type!='publisher' entry must not skip the
                // chain check and inject an attacker-controlled root (peer-review F7).
                if ($type !== 'publisher') {
                    $this->audit->log('trust_anchor.rejected', 'trust_anchor', (string)($a['key_id'] ?? ''), [
                        'newValue' => ['reason' => 'Nur Publisher-Anker per Sync erlaubt (type=' . $type . ')'],
                    ]);
                    continue;
                }
                $check = $chain->verifyPublisherCert($a);
                if (!$check['ok']) {
                    $this->audit->log('trust_anchor.rejected', 'trust_anchor', (string)($a['key_id'] ?? ''), [
                        'newValue' => ['reason' => $check['reason'] ?? 'Kette ungültig'],
                    ]);
                    continue;
                }
                $this->trust->addAnchor(
                    (string)$a['key_id'],
                    (string)$a['public_key'],
                    'publisher', // force publisher; never trust the document's declared type
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

    /** @return array<string, mixed>|null Verified metadata. */
    public function metadata(): ?array
    {
        return $this->verifySigned($this->fetch('metadata.json'));
    }

    private function setMeta(string $key, string $value): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO marketplace_meta (meta_key, meta_value, updated_at) VALUES (:k, :v, now()) '
            . 'ON CONFLICT (meta_key) DO UPDATE SET meta_value = EXCLUDED.meta_value, updated_at = now()',
            ['k' => $key, 'v' => $value],
        );
    }

    /**
     * State of the revocation list (CRL): timestamp of the last successful fetch,
     * age in days, the threshold and the stale flag (ch. 24.9.2).
     *
     * @return array{last_fetch_at: ?string, age_days: ?int, max_age_days: int, stale: bool}
     */
    public function crlState(): array
    {
        $maxAge = (int)$this->settings->get('core', 'crl_max_age_days', 7);
        $row = ConnectionManager::get('default')->execute(
            "SELECT meta_value FROM marketplace_meta WHERE meta_key = 'last_crl_fetch_at'",
        )->fetch('assoc');

        $last = $row['meta_value'] ?? null;
        $ageDays = null;
        $stale = true;
        if ($last !== null) {
            $ts = strtotime((string)$last);
            if ($ts !== false) {
                $ageDays = (int)floor((time() - $ts) / 86400);
                $stale = $maxAge > 0 && $ageDays > $maxAge;
            }
        }

        return ['last_fetch_at' => $last, 'age_days' => $ageDays, 'max_age_days' => $maxAge, 'stale' => $stale];
    }
}
