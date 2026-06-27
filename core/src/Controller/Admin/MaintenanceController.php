<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditLogger;
use App\Service\System\AllowTokenCookie;
use App\Service\System\CriticalActionService;
use App\Service\System\MaintenanceService;
use App\Service\System\QuiesceService;
use Cake\Http\Response;
use Throwable;

/**
 * Maintenance mode GUI — Phase 3 (docs/maintenance-mode-design.md §4.3).
 *
 * Engages the platform-wide maintenance window (locking everyone but the operator
 * out via {@see \App\Middleware\SelectiveMaintenanceMiddleware}) and drains the
 * workers ({@see QuiesceService}). The page polls {@see status()} to show the live
 * in-flight count while the quiesce drains. Gated by the dedicated
 * `system_maintenance` admin area.
 */
class MaintenanceController extends AdminController
{
    protected ?string $requiredArea = 'system_maintenance';

    public function index(): void
    {
        $session = (new MaintenanceService())->activeSession();
        $blocking = 0;
        $needsAck = 0;
        $actionList = [];
        if ($session !== null) {
            $actions = new CriticalActionService();
            $actions->maintenanceRecoverStale(); // reflect crashed actions in the view
            $blocking = $actions->nonTerminalCount((string)$session['id']);
            $needsAck = $actions->unacknowledgedManualRestoreCount((string)$session['id']);
            $actionList = $actions->forSession((string)$session['id']);
        }
        $this->set('session', $session);
        $this->set('quiesce', $session !== null ? (new QuiesceService())->status() : null);
        $this->set('blockingActions', $blocking);
        $this->set('needsAck', $needsAck);
        $this->set('actions', $actionList);
    }

    /**
     * Engages maintenance. ENTER order (design §4.2): atomic engage (the lockout)
     * FIRST — if another operator already holds the window we lose the race and
     * abort — then issue the allow-token cookie, then ask the workers to pause and
     * drain. The drain runs asynchronously; the page polls {@see status()}.
     */
    public function engage(): ?Response
    {
        $this->request->allowMethod('post');

        $actorId = $this->actorId();
        $reason = trim((string)$this->request->getData('reason'));
        $reason = $reason !== '' ? $reason : null;

        $plain = bin2hex(random_bytes(AllowTokenCookie::TOKEN_BYTES));
        $session = (new MaintenanceService())->engage($actorId, hash('sha256', $plain), $reason);
        if ($session === null) {
            $this->Flash->error(__('flash.maintenance.already_active'));

            return $this->redirect(['action' => 'index']);
        }

        (new QuiesceService())->pause($actorId, (string)$session['id']);
        $this->audit('maintenance.engage', (string)$session['id'], $actorId, ['reason' => $reason]);
        $this->Flash->success(__('flash.maintenance.engaged'));

        // The cookie keeps THIS operator in (alongside the actor-id fallback).
        /** @var \Cake\Http\Response $redirect */
        $redirect = $this->redirect(['action' => 'index']);

        return $redirect->withCookie(AllowTokenCookie::make($plain));
    }

    /**
     * Releases maintenance. EXIT order (design §4.3): sweep crashed actions FIRST so a
     * dead process cannot deadlock the exit, then close the session ATOMICALLY — the
     * conditional close in {@see MaintenanceService::releaseIfStable()} succeeds only
     * when nothing of this session is in flight AND no `needs_manual_restore` is still
     * unacknowledged ("Flag HALTEN", §4.2). The workers are resumed and the allow-token
     * cookie cleared ONLY after a successful close; on a refused close the workers stay
     * paused and the operator must finish (or acknowledge) the pending action first.
     */
    public function release(): ?Response
    {
        $this->request->allowMethod('post');

        $maint = new MaintenanceService();
        $session = $maint->activeSession();
        if ($session === null) {
            $this->Flash->error(__('flash.maintenance.not_active'));

            return $this->redirect(['action' => 'index']);
        }

        // Exit only when stable (decision #4): sweep crashed actions first so a dead
        // process cannot deadlock the exit, then close ATOMICALLY — the close happens
        // only if nothing of this session is still in flight (one conditional UPDATE).
        (new CriticalActionService())->maintenanceRecoverStale();
        if (!$maint->releaseIfStable((string)$session['id'])) {
            $this->Flash->error(__('flash.maintenance.action_in_progress'));

            return $this->redirect(['action' => 'index']); // workers stay paused
        }

        (new QuiesceService())->resume();
        $this->audit('maintenance.release', (string)$session['id'], $this->actorId(), []);
        $this->Flash->success(__('flash.maintenance.released'));

        /** @var \Cake\Http\Response $redirect */
        $redirect = $this->redirect(['action' => 'index']);

        return $redirect->withCookie(AllowTokenCookie::expire());
    }

    /**
     * Operator acknowledgement of a `needs_manual_restore` action (Stufe-1 restore
     * handling, decision #7). NON-destructive: it restores NOTHING — the global
     * restore stays CLI-only — it only records that the operator has restored/resolved
     * the action out of band (the GUI shows the exact `bin/cake backup restore` /
     * `test-restore` commands + the pre-action backup id). That lifts the "Flag
     * HALTEN" (§4.2) so the maintenance window can then be released. Scoped to the
     * active session; the optional note is kept in the audit trail only.
     */
    public function acknowledgeRestore(): ?Response
    {
        $this->request->allowMethod('post');

        $session = (new MaintenanceService())->activeSession();
        if ($session === null) {
            $this->Flash->error(__('flash.maintenance.not_active'));

            return $this->redirect(['action' => 'index']);
        }
        $actionId = trim((string)$this->request->getData('action_id'));
        if ($actionId === '' || !$this->isUuid($actionId)) {
            // A malformed id would raise PG 22P02 on the raw bind (-> HTTP 500); fail
            // it cleanly like an unknown id instead (cf. peer admin controllers).
            $this->Flash->error(__('flash.maintenance.restore_ack_failed'));

            return $this->redirect(['action' => 'index']);
        }
        $actorId = $this->actorId();
        $ok = (new CriticalActionService())->acknowledgeManualRestore($actionId, (string)$session['id'], $actorId);
        if (!$ok) {
            // Not a needs_manual_restore of this session, or already acknowledged.
            $this->Flash->error(__('flash.maintenance.restore_ack_failed'));

            return $this->redirect(['action' => 'index']);
        }
        $note = trim((string)$this->request->getData('note'));
        $this->audit('maintenance.action.acknowledge_restore', (string)$session['id'], $actorId, [
            'action_id' => $actionId,
            'note' => $note !== '' ? mb_substr($note, 0, 500) : null,
        ]);
        $this->Flash->success(__('flash.maintenance.restore_acknowledged'));

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Queues a PROTECTED module install as a critical action (Phase 6). Only while
     * maintenance is engaged: the upload is stored and a `module_install`
     * critical_action is enqueued; the worker runs it (backup → install → verify →
     * rollback) once the platform has drained, and the exit gate blocks release until
     * it reaches a terminal state.
     */
    public function installModule(): ?Response
    {
        $this->request->allowMethod('post');

        $session = (new MaintenanceService())->activeSession();
        if ($session === null) {
            $this->Flash->error(__('flash.maintenance.not_active'));

            return $this->redirect(['action' => 'index']);
        }
        $file = $this->request->getUploadedFile('package');
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            $this->Flash->error(__('flash.module.no_package'));

            return $this->redirect(['action' => 'index']);
        }
        if (strtolower(pathinfo((string)$file->getClientFilename(), PATHINFO_EXTENSION)) !== 'zip') {
            $this->Flash->error(__('flash.module.not_zip'));

            return $this->redirect(['action' => 'index']);
        }

        $dir = TMP . 'module_uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.zip';
        $file->moveTo($path);

        $actorId = $this->actorId();
        $this->enqueueProtected('module_install', (string)$session['id'], $actorId, [
            'package_path' => $path,
        ]);

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Queues a PROTECTED tenant provision as a critical action (Phase 6 Increment 2).
     * Only while maintenance is engaged; the worker runs it (backup → create → verify
     * → rollback) once drained.
     */
    public function provisionTenant(): ?Response
    {
        $this->request->allowMethod('post');

        $session = (new MaintenanceService())->activeSession();
        if ($session === null) {
            $this->Flash->error(__('flash.maintenance.not_active'));

            return $this->redirect(['action' => 'index']);
        }
        $key = trim((string)$this->request->getData('key'));
        $name = trim((string)$this->request->getData('name'));
        if ($key === '' || $name === '') {
            $this->Flash->error(__('flash.maintenance.tenant_params'));

            return $this->redirect(['action' => 'index']);
        }

        $actorId = $this->actorId();
        $this->enqueueProtected('tenant_provision', (string)$session['id'], $actorId, [
            'key' => $key,
            'name' => $name,
        ]);

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Queues a PROTECTED secret-key rotation as a critical action (Phase 6 Inc 3).
     * The OLD key must already be deployed to the environment
     * (SECURITY_ENCRYPTION_KEY_OLD[_FILE]); it is never sent through the form.
     */
    public function rotateSecret(): ?Response
    {
        $this->request->allowMethod('post');

        $session = (new MaintenanceService())->activeSession();
        if ($session === null) {
            $this->Flash->error(__('flash.maintenance.not_active'));

            return $this->redirect(['action' => 'index']);
        }
        $actorId = $this->actorId();
        $this->enqueueProtected('secret_rotate', (string)$session['id'], $actorId, []);

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Queues a PROTECTED trust-anchor rotation as a critical action (Phase 6 Inc 4).
     * The new anchor must already be installed; the old one expires after the overlap.
     */
    public function rotateTrust(): ?Response
    {
        $this->request->allowMethod('post');

        $session = (new MaintenanceService())->activeSession();
        if ($session === null) {
            $this->Flash->error(__('flash.maintenance.not_active'));

            return $this->redirect(['action' => 'index']);
        }
        $old = trim((string)$this->request->getData('old_key_id'));
        $new = trim((string)$this->request->getData('new_key_id'));
        if ($old === '' || $new === '') {
            $this->Flash->error(__('flash.maintenance.trust_params'));

            return $this->redirect(['action' => 'index']);
        }
        $overlap = max(0, (int)$this->request->getData('overlap_days'));

        $actorId = $this->actorId();
        $this->enqueueProtected('trust_rotate', (string)$session['id'], $actorId, [
            'old_key_id' => $old,
            'new_key_id' => $new,
            'overlap_days' => $overlap > 0 ? $overlap : 30,
        ]);

        return $this->redirect(['action' => 'index']);
    }

    /** JSON drain status polled by the index page while maintenance is engaged. */
    public function status(): Response
    {
        $this->request->allowMethod('get');

        $session = (new MaintenanceService())->activeSession();
        if ($session === null) {
            $payload = ['active' => false];
        } else {
            $actions = new CriticalActionService();
            $actions->maintenanceRecoverStale();
            $blocking = $actions->nonTerminalCount((string)$session['id']);
            $needsAck = $actions->unacknowledgedManualRestoreCount((string)$session['id']);
            $payload = ['active' => true]
                + (new QuiesceService())->status()
                + [
                    'blocking_actions' => $blocking,
                    'needs_ack' => $needsAck,
                    'can_release' => $blocking === 0 && $needsAck === 0,
                ];
        }

        return $this->response->withType('application/json')->withStringBody((string)json_encode($payload));
    }

    private function actorId(): ?string
    {
        $id = $this->identity()?->getIdentifier();

        return is_string($id) ? $id : null;
    }

    /**
     * Enqueues a protected action and reports the outcome: on success it audits and
     * flashes "queued"; on a refuse-when-closing (the session was released between the
     * active-session check above and the enqueue — {@see CriticalActionService::enqueue()}
     * inserts only while the session is open) it flashes "not active". The caller
     * redirects to index either way.
     *
     * @param array<string,mixed> $payload
     */
    private function enqueueProtected(string $type, string $sessionId, ?string $actorId, array $payload): void
    {
        $action = (new CriticalActionService())->enqueue($type, $sessionId, $actorId, $payload);
        if ($action === null) {
            $this->Flash->error(__('flash.maintenance.not_active'));

            return;
        }
        $this->audit('maintenance.action.enqueue', $sessionId, $actorId, [
            'type' => $type,
            'action_id' => $action['id'],
        ]);
        $this->Flash->success(__('flash.maintenance.action_queued'));
    }

    /** @param array<string,mixed> $detail */
    private function audit(string $action, string $sessionId, ?string $actorId, array $detail): void
    {
        try {
            (new AuditLogger())->log($action, 'maintenance_session', $sessionId, [
                'actorUserId' => $actorId,
                'newValue' => $detail === [] ? null : $detail,
                'component' => 'core',
            ]);
        } catch (Throwable) {
            // Audit is a side effect; it must never break engage/release.
        }
    }
}
