<?php
declare(strict_types=1);

namespace App\Service\License;

use App\Audit\AuditLogger;
use App\Service\Security\Signer;
use App\Service\Security\TrustStore;
use Cake\Datasource\ConnectionManager;
use RuntimeException;

/**
 * Offline-first-Lizenzierung (Kap. 28.7, Entscheidung 158).
 *
 * Maßgeblich ist die **signierte Lizenzdatei**: Signatur (gegen Vertrauensanker),
 * Modulbezug und Gültigkeitszeitraum werden ohne Serverkontakt geprüft. Ablauf →
 * Modul deaktivierbar (kein Datenverlust). Optionale Online-Enforcement +
 * Karenzfenster sind in der Lizenz deklariert.
 */
class LicenseService
{
    private Signer $signer;
    private TrustStore $trust;
    private AuditLogger $audit;

    public function __construct(?Signer $signer = null, ?TrustStore $trust = null, ?AuditLogger $audit = null)
    {
        $this->signer = $signer ?? new Signer();
        $this->trust = $trust ?? new TrustStore();
        $this->audit = $audit ?? new AuditLogger();
    }

    private function conn()
    {
        return ConnectionManager::get('default');
    }

    /**
     * Kanonische Serialisierung des Lizenz-Payloads (Signaturbasis).
     *
     * @param array<string, mixed> $payload
     */
    public static function canonical(array $payload): string
    {
        return (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Validiert eine Lizenzdatei (offline) ohne sie zu speichern.
     *
     * @return array{ok: bool, reason?: string, payload?: array<string,mixed>, key_id?: string}
     */
    public function validateFile(string $licenseJson): array
    {
        $data = json_decode($licenseJson, true);
        if (!is_array($data) || !isset($data['payload'], $data['signature'], $data['key_id'])) {
            return ['ok' => false, 'reason' => 'Ungültige Lizenzdatei.'];
        }
        $keyId = (string)$data['key_id'];
        if ($this->trust->isRevoked($keyId)) {
            return ['ok' => false, 'reason' => "Signaturschlüssel widerrufen: $keyId"];
        }
        $anchor = $this->trust->getAnchor($keyId);
        if ($anchor === null) {
            return ['ok' => false, 'reason' => "Unbekannter Vertrauensanker: $keyId"];
        }
        if (!$this->signer->verify(self::canonical($data['payload']), (string)$data['signature'], (string)$anchor['public_key'])) {
            return ['ok' => false, 'reason' => 'Lizenzsignatur ungültig.'];
        }

        return ['ok' => true, 'payload' => $data['payload'], 'key_id' => $keyId];
    }

    /**
     * Installiert/aktualisiert eine Lizenz nach erfolgreicher Validierung.
     */
    public function install(string $licenseJson): array
    {
        $result = $this->validateFile($licenseJson);
        if (!$result['ok']) {
            throw new RuntimeException($result['reason'] ?? 'Lizenz ungültig.');
        }
        $p = $result['payload'];
        $moduleKey = (string)($p['module_ref'] ?? '');
        if ($moduleKey === '') {
            throw new RuntimeException('Lizenz ohne module_ref.');
        }
        $data = json_decode($licenseJson, true);

        $this->conn()->execute(
            'INSERT INTO licenses (module_key, license_data, signature, signed_key_id, valid_from, valid_to, '
            . 'grace_window_days, online_enforcement, status) VALUES (:k, CAST(:ld AS jsonb), :sig, :kid, '
            . ":vf, :vt, :gw, :oe, 'active') "
            . 'ON CONFLICT (module_key) DO UPDATE SET license_data = EXCLUDED.license_data, '
            . 'signature = EXCLUDED.signature, signed_key_id = EXCLUDED.signed_key_id, '
            . 'valid_from = EXCLUDED.valid_from, valid_to = EXCLUDED.valid_to, '
            . "grace_window_days = EXCLUDED.grace_window_days, online_enforcement = EXCLUDED.online_enforcement, status = 'active'",
            [
                'k' => $moduleKey,
                'ld' => (string)json_encode($p),
                'sig' => (string)$data['signature'],
                'kid' => (string)$data['key_id'],
                'vf' => $p['valid_from'] ?? null,
                'vt' => $p['valid_to'] ?? null,
                'gw' => isset($p['grace_window_days']) ? (int)$p['grace_window_days'] : null,
                'oe' => !empty($p['online_enforcement']) ? 'true' : 'false',
            ],
        );

        $this->audit->log('license.install', 'module', $moduleKey, [
            'newValue' => ['valid_to' => $p['valid_to'] ?? null, 'issuer' => $p['issuer'] ?? null],
            'moduleKey' => $moduleKey,
        ]);

        return $this->evaluate($moduleKey);
    }

    /**
     * Bewertet den aktuellen Lizenzstatus eines Moduls (offline).
     *
     * @return array{status: string, reason?: string}
     */
    public function evaluate(string $moduleKey): array
    {
        $row = $this->conn()->execute(
            'SELECT signed_key_id, valid_to, status FROM licenses WHERE module_key = :k',
            ['k' => $moduleKey],
        )->fetch('assoc');
        if ($row === false) {
            return ['status' => 'missing', 'reason' => 'Keine Lizenz vorhanden.'];
        }
        if ($this->trust->isRevoked((string)$row['signed_key_id'])) {
            return ['status' => 'revoked', 'reason' => 'Signaturschlüssel widerrufen.'];
        }
        if ($row['valid_to'] !== null) {
            $validTo = strtotime((string)$row['valid_to']);
            if ($validTo !== false && $validTo < time()) {
                return ['status' => 'expired', 'reason' => 'Gültigkeitszeitraum abgelaufen.'];
            }
        }

        return ['status' => 'valid'];
    }

    public function isValid(string $moduleKey): bool
    {
        return $this->evaluate($moduleKey)['status'] === 'valid';
    }
}
