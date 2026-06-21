<?php
declare(strict_types=1);

namespace App\Service\Security;

use App\Audit\AuditLogger;
use App\Infrastructure\Db;
use RuntimeException;

/**
 * Rolling trust-anchor rotation (ch. 1.4) — extracted from the CLI
 * {@see \App\Command\TrustCommand} so the maintenance critical-action handler
 * ({@see \App\Service\System\TrustRotateHandler}) can reuse it. The new anchor
 * becomes valid immediately and indefinitely while the old one expires after an
 * overlap window (both accepted until then → no outage). Improvements over the
 * original CLI rotate: the two updates run in ONE transaction, the rotation is
 * AUDITED, and a SNAPSHOT of the prior validity windows is returned so a critical
 * action can cleanly roll it back.
 */
class TrustRotationService
{
    private TrustStore $trust;

    public function __construct(private ?AuditLogger $audit = null, ?TrustStore $trust = null)
    {
        $this->trust = $trust ?? new TrustStore();
    }

    /**
     * Rotates: $newKeyId valid indefinitely, $oldKeyId expires after $overlapDays.
     * Returns a snapshot of both anchors' prior validity windows (keyed by key_id)
     * for {@see restore()}. Throws if the new anchor is not installed.
     *
     * @return array<string, array{valid_from:?string, valid_to:?string}>
     */
    public function rotate(string $oldKeyId, string $newKeyId, int $overlapDays): array
    {
        if ($oldKeyId === '' || $newKeyId === '') {
            throw new RuntimeException('rotate benötigt alte und neue Schlüssel-ID.');
        }
        if ($this->trust->getAnchor($newKeyId) === null) {
            throw new RuntimeException("Neuer Anker nicht aktiv/vorhanden: $newKeyId (zuerst hinzufügen).");
        }
        $days = max(0, $overlapDays);
        $conn = Db::privileged();

        $snapshot = [];
        foreach ([$newKeyId, $oldKeyId] as $kid) {
            $row = $conn->execute(
                'SELECT valid_from, valid_to FROM trust_anchors WHERE key_id = :k',
                ['k' => $kid],
            )->fetch('assoc');
            if ($row !== false) {
                $snapshot[$kid] = ['valid_from' => $row['valid_from'], 'valid_to' => $row['valid_to']];
            }
        }

        $conn->transactional(function () use ($conn, $oldKeyId, $newKeyId, $days): void {
            $conn->execute(
                'UPDATE trust_anchors SET valid_from = COALESCE(valid_from, now()), valid_to = NULL WHERE key_id = :k',
                ['k' => $newKeyId],
            );
            $conn->execute(
                "UPDATE trust_anchors SET valid_to = now() + (:d || ' days')::interval WHERE key_id = :k",
                ['d' => (string)$days, 'k' => $oldKeyId],
            );
        });

        ($this->audit ??= new AuditLogger())->log('trust.rotate', 'trust_anchor', $newKeyId, [
            'component' => 'core',
            'newValue' => ['old' => $oldKeyId, 'overlap_days' => $days],
        ]);

        return $snapshot;
    }

    /**
     * Restores anchors' prior validity windows (the action-own rollback).
     *
     * @param array<string, array{valid_from:?string, valid_to:?string}> $snapshot
     */
    public function restore(array $snapshot): void
    {
        if ($snapshot === []) {
            return;
        }
        $conn = Db::privileged();
        $conn->transactional(function () use ($conn, $snapshot): void {
            foreach ($snapshot as $keyId => $window) {
                $conn->execute(
                    'UPDATE trust_anchors SET valid_from = :vf, valid_to = :vt WHERE key_id = :k',
                    ['vf' => $window['valid_from'], 'vt' => $window['valid_to'], 'k' => $keyId],
                );
            }
        });
    }
}
