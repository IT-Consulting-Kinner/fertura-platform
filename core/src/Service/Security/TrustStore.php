<?php
declare(strict_types=1);

namespace App\Service\Security;

use Cake\Datasource\ConnectionManager;

/**
 * Verwaltet Vertrauensanker (Root-/Publisher-Schlüssel) und die Sperrliste
 * (Kap. 24.9.2).
 */
class TrustStore
{
    private function conn()
    {
        return ConnectionManager::get('default');
    }

    public function addAnchor(
        string $keyId,
        string $publicKey,
        string $type = 'root',
        ?string $publisher = null,
        ?string $signedBy = null,
    ): void {
        $this->conn()->execute(
            'INSERT INTO trust_anchors (key_id, public_key, key_type, publisher, signed_by, active) '
            . 'VALUES (:id, :pk, :t, :pub, :sb, true) '
            . 'ON CONFLICT (key_id) DO UPDATE SET public_key = EXCLUDED.public_key, '
            . 'key_type = EXCLUDED.key_type, publisher = EXCLUDED.publisher, '
            . 'signed_by = EXCLUDED.signed_by, active = true',
            ['id' => $keyId, 'pk' => $publicKey, 't' => $type, 'pub' => $publisher, 'sb' => $signedBy],
        );
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
    }
}
