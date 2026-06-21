<?php
declare(strict_types=1);

namespace App\Service\System;

use App\Infrastructure\Db;
use App\Service\Security\TrustRotationService;

/**
 * The trust-anchor rotation critical action — Phase 6 (Increment 4).
 *
 * payload: {old_key_id, new_key_id, overlap_days?}. execute() rolls the anchors over
 * (new valid indefinitely, old expires after the overlap) — transactional + audited
 * via {@see TrustRotationService} — and captures a SNAPSHOT of the prior validity
 * windows. verify() asserts the new anchor is active and open-ended. rollback() is a
 * CLEAN action-own undo: it restores the snapshot (unlike secret rotation, trust
 * rotation only touches two validity windows, which are fully reversible). The
 * snapshot lives on the handler instance (one per worker tick).
 */
class TrustRotateHandler implements CriticalActionHandler
{
    /** @var array<string, array{valid_from:?string, valid_to:?string}> */
    private array $snapshot = [];

    public function __construct(private TrustRotationService $rotation = new TrustRotationService())
    {
    }

    public function type(): string
    {
        return 'trust_rotate';
    }

    public function execute(array $payload): void
    {
        $this->snapshot = $this->rotation->rotate(
            (string)($payload['old_key_id'] ?? ''),
            (string)($payload['new_key_id'] ?? ''),
            (int)($payload['overlap_days'] ?? 30),
        );
    }

    public function verify(array $payload): array
    {
        $new = (string)($payload['new_key_id'] ?? '');
        if ($new === '') {
            return ['ok' => false, 'reason' => 'new_key_id fehlt.'];
        }
        $row = Db::privileged()->execute(
            'SELECT valid_to, active FROM trust_anchors WHERE key_id = :k',
            ['k' => $new],
        )->fetch('assoc');
        if ($row === false) {
            return ['ok' => false, 'reason' => 'Neuer Anker fehlt nach Rotation: ' . $new];
        }
        if (!($row['active'] === true || $row['active'] === 't')) {
            return ['ok' => false, 'reason' => 'Neuer Anker inaktiv.'];
        }
        if ($row['valid_to'] !== null) {
            return ['ok' => false, 'reason' => 'Neuer Anker hat ein valid_to (sollte unbegrenzt gelten).'];
        }

        return ['ok' => true, 'reason' => null];
    }

    public function rollback(array $payload): void
    {
        // Clean action-own undo: restore both anchors' prior validity windows.
        $this->rotation->restore($this->snapshot);
    }
}
