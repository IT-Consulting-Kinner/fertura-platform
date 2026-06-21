<?php
declare(strict_types=1);

namespace App\Service\System;

use App\Service\Backup\BackupService;
use Throwable;

/**
 * Drives a queued critical action through the protected sequence — Phase 6
 * (docs/maintenance-mode-design.md §4.2): claim → PRE_ACTION_BACKUP → ACTION →
 * VERIFY → succeed, with ROLLBACK (action-own undo) on execute/verify failure and
 * escalation to `needs_manual_restore` when the undo itself fails.
 *
 * Runs in the WORKER, called from the pause-gate: during maintenance the worker's
 * normal cycle is paused, but it processes the engaged session's critical actions —
 * and ONLY once the platform has drained (in-flight == 0), so the action mutates a
 * quiet system. The action's `fence_token` is threaded through every transition, so
 * a stale process whose token was rotated by the recovery sweep cannot advance it.
 */
class CriticalActionRunner
{
    private MaintenanceService $maintenance;
    private QuiesceService $quiesce;

    public function __construct(
        private CriticalActionService $actions = new CriticalActionService(),
        private CriticalActionRegistry $registry = new CriticalActionRegistry(),
        ?MaintenanceService $maintenance = null,
        ?QuiesceService $quiesce = null,
        private ?BackupService $backup = null,
    ) {
        $this->maintenance = $maintenance ?? new MaintenanceService();
        $this->quiesce = $quiesce ?? new QuiesceService();
    }

    /**
     * Processes at most one queued critical action of the active maintenance session,
     * IF the platform has drained. Returns the action id, or null when nothing ran.
     */
    public function tick(): ?string
    {
        $session = $this->maintenance->activeSession();
        if ($session === null) {
            return null; // protected actions only run inside a maintenance window
        }
        if ($this->quiesce->inFlight()['total'] > 0) {
            return null; // wait for the workers to drain before mutating (design QUIESCE)
        }
        $action = $this->actions->claimEngaged((string)$session['id']);
        if ($action === null) {
            return null;
        }

        $id = (string)$action['id'];
        $fence = (string)$action['fence_token'];
        $actorId = isset($session['actor_user_id']) ? (string)$session['actor_user_id'] : null;
        $payload = $this->decodePayload($action['payload'] ?? null);

        $handler = $this->registry->get((string)$action['type']);
        if ($handler === null) {
            $this->actions->markFailed($id, 'Kein Handler fuer Aktionstyp: ' . $action['type'], $fence);

            return $id;
        }

        $this->drive($id, $fence, $handler, $payload, $actorId);

        return $id;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function drive(string $id, string $fence, CriticalActionHandler $handler, array $payload, ?string $actorId): void
    {
        // PRE_ACTION_BACKUP — already in backing_up via claimEngaged(). A failure here
        // leaves the action pre-mutation, which the recovery sweep aborts cleanly.
        try {
            $backupId = $this->backupService()->context('gui', $actorId)->createLocked('pre-action ' . $id, $actorId);
            $this->actions->attachBackup($id, $backupId);
        } catch (Throwable $e) {
            $this->actions->markFailed($id, 'Pflicht-Backup fehlgeschlagen: ' . $e->getMessage(), $fence);

            return;
        }

        // ACTION.
        if (!$this->actions->transition($id, 'running', $fence)) {
            return; // fenced/swept mid-flight — abandon
        }
        try {
            $this->actions->heartbeat($id, $fence);
            $handler->execute($payload);
        } catch (Throwable $e) {
            $this->rollback($id, $fence, $handler, $payload, 'Aktion fehlgeschlagen: ' . $e->getMessage());

            return;
        }

        // VERIFY.
        if (!$this->actions->transition($id, 'verifying', $fence)) {
            return;
        }
        $verdict = $this->safeVerify($handler, $payload);
        if (($verdict['ok'] ?? false) !== true) {
            $this->rollback($id, $fence, $handler, $payload, 'Verifikation fehlgeschlagen: ' . ($verdict['reason'] ?? ''));

            return;
        }

        $this->actions->markSucceeded($id, $fence);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function rollback(string $id, string $fence, CriticalActionHandler $handler, array $payload, string $reason): void
    {
        if (!$this->actions->transition($id, 'rolling_back', $fence)) {
            return;
        }
        try {
            $handler->rollback($payload);
            $this->actions->markFailed($id, $reason . ' [zurueckgerollt]', $fence);
        } catch (Throwable $e) {
            // Action-own undo failed -> hold maintenance; operator restores via CLI.
            $this->actions->markNeedsManualRestore(
                $id,
                $reason . ' [Rollback fehlgeschlagen: ' . $e->getMessage() . ']',
                $fence,
            );
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool, reason:?string}
     */
    private function safeVerify(CriticalActionHandler $handler, array $payload): array
    {
        try {
            return $handler->verify($payload);
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => 'Verifikation warf: ' . $e->getMessage()];
        }
    }

    private function backupService(): BackupService
    {
        return $this->backup ?? new BackupService();
    }

    /**
     * @return array<string,mixed>
     */
    private function decodePayload(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
