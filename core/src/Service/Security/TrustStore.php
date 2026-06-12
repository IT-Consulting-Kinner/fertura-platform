<?php
declare(strict_types=1);

namespace App\Service\Security;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * Manages trust anchors (root/publisher keys) and the revocation list
 * (ch. 24.9.2).
 */
class TrustStore
{
    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    public function addAnchor(
        string $keyId,
        string $publicKey,
        string $type = 'root',
        ?string $publisher = null,
        ?string $signedBy = null,
        ?string $keySignature = null,
        ?string $validFrom = null,
        ?string $validTo = null,
    ): void {
        $this->conn()->execute(
            'INSERT INTO trust_anchors (key_id, public_key, key_type, publisher, signed_by, key_signature, valid_from, valid_to, active) '
            . 'VALUES (:id, :pk, :t, :pub, :sb, :ks, :vf, :vt, true) '
            . 'ON CONFLICT (key_id) DO UPDATE SET public_key = EXCLUDED.public_key, '
            . 'key_type = EXCLUDED.key_type, publisher = EXCLUDED.publisher, '
            . 'signed_by = EXCLUDED.signed_by, key_signature = EXCLUDED.key_signature, '
            . 'valid_from = EXCLUDED.valid_from, valid_to = EXCLUDED.valid_to, active = true',
            [
                'id' => $keyId, 'pk' => $publicKey, 't' => $type, 'pub' => $publisher,
                'sb' => $signedBy, 'ks' => $keySignature,
                'vf' => $validFrom ?: null, 'vt' => $validTo ?: null,
            ],
        );
    }

    /**
     * Checks an anchor's validity window (ch. 24.9.2). NULL bounds mean
     * unbounded. Enforced on all verification paths (package, chain, license).
     *
     * @param array<string, mixed> $anchor
     * @return array{ok: bool, reason: ?string}
     */
    public static function validity(array $anchor, ?int $now = null): array
    {
        $now ??= time();
        $from = $anchor['valid_from'] ?? null;
        $to = $anchor['valid_to'] ?? null;
        if ($from !== null && $from !== '' && (int)strtotime((string)$from) > $now) {
            return ['ok' => false, 'reason' => 'Anker noch nicht gültig (valid_from ' . $from . ')'];
        }
        if ($to !== null && $to !== '' && (int)strtotime((string)$to) < $now) {
            return ['ok' => false, 'reason' => 'Anker abgelaufen (valid_to ' . $to . ')'];
        }

        return ['ok' => true, 'reason' => null];
    }

    /** @return array<string, mixed>|null */
    public function getAnchor(string $keyId): ?array
    {
        $row = $this->conn()->execute(
            'SELECT * FROM trust_anchors WHERE key_id = :id AND active',
            ['id' => $keyId],
        )->fetch('assoc');

        return $row ?: null;
    }

    public function isRevoked(string $keyId): bool
    {
        return $this->conn()->execute(
            'SELECT 1 FROM revoked_keys WHERE key_id = :id',
            ['id' => $keyId],
        )->fetch() !== false;
    }

    public function revokeKey(string $keyId, ?string $reason = null, string $source = 'manual'): void
    {
        $this->conn()->execute(
            'INSERT INTO revoked_keys (key_id, reason, source) VALUES (:id, :r, :s) '
            . 'ON CONFLICT (key_id) DO NOTHING',
            ['id' => $keyId, 'r' => $reason, 's' => $source],
        );
        // Mark installed modules signed with this key as "signature revoked"
        // (ch. 24.9.2).
        $this->reconcileModuleSignatures();
    }

    /**
     * Reconciles installed modules against the revocation list and marks
     * affected ones as signature_status='revoked' (ch. 24.9.2). No
     * auto-disabling; just flagging plus an admin warning.
     *
     * @return int Number of modules newly marked as revoked.
     */
    public function reconcileModuleSignatures(): int
    {
        $rows = $this->conn()->execute(
            "UPDATE modules SET signature_status = 'revoked' "
            . 'WHERE signature_key_id IN (SELECT key_id FROM revoked_keys) '
            . "AND signature_status <> 'revoked' RETURNING module_key",
        )->fetchAll('assoc');

        return count($rows);
    }
}
