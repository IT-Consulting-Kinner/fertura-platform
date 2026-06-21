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
        $actionList = [];
        if ($session !== null) {
            $actions = new CriticalActionService();
            $actions->maintenanceRecoverStale(); // reflect crashed actions in the view
            $blocking = $actions->nonTerminalCount((string)$session['id']);
            $actionList = $actions->forSession((string)$session['id']);
        }
        $this->set('session', $session);
        $this->set('quiesce', $session !== null ? (new QuiesceService())->status() : null);
        $this->set('blockingActions', $blocking);
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
     * Releases maintenance. EXIT order: resume the workers FIRST, then close the
     * session (so no request slips through against a half-released state), then
     * clear the allow-token cookie. (The "exit only when stable" critical-action
     * gate arrives in Phase 4; here the operator may release at any time.)
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
        $isolation = (string)$this->request->getData('isolation') === 'out_of_process' ? 'out_of_process' : 'in_process';

        $dir = TMP . 'module_uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.zip';
        $file->moveTo($path);

        $actorId = $this->actorId();
        $action = (new CriticalActionService())->enqueue(
            'module_install',
            (string)$session['id'],
            $actorId,
            ['package_path' => $path, 'isolation' => $isolation],
        );
        $this->audit('maintenance.action.enqueue', (string)$session['id'], $actorId, [
            'type' => 'module_install',
            'action_id' => $action['id'],
        ]);
        $this->Flash->success(__('flash.maintenance.action_queued'));

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
        $action = (new CriticalActionService())->enqueue(
            'tenant_provision',
            (string)$session['id'],
            $actorId,
            ['key' => $key, 'name' => $name],
        );
        $this->audit('maintenance.action.enqueue', (string)$session['id'], $actorId, [
            'type' => 'tenant_provision',
            'action_id' => $action['id'],
        ]);
        $this->Flash->success(__('flash.maintenance.action_queued'));

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
            $payload = ['active' => true]
                + (new QuiesceService())->status()
                + ['blocking_actions' => $blocking, 'can_release' => $blocking === 0];
        }

        return $this->response->withType('application/json')->withStringBody((string)json_encode($payload));
    }

    private function actorId(): ?string
    {
        $id = $this->identity()?->getIdentifier();

        return is_string($id) ? $id : null;
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
