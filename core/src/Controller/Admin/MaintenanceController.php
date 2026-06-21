<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditLogger;
use App\Service\System\AllowTokenCookie;
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
        $this->set('session', $session);
        $this->set('quiesce', $session !== null ? (new QuiesceService())->status() : null);
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

        $session = (new MaintenanceService())->activeSession();
        if ($session === null) {
            $this->Flash->error(__('flash.maintenance.not_active'));

            return $this->redirect(['action' => 'index']);
        }

        (new QuiesceService())->resume();
        (new MaintenanceService())->release((string)$session['id']);
        $this->audit('maintenance.release', (string)$session['id'], $this->actorId(), []);
        $this->Flash->success(__('flash.maintenance.released'));

        /** @var \Cake\Http\Response $redirect */
        $redirect = $this->redirect(['action' => 'index']);

        return $redirect->withCookie(AllowTokenCookie::expire());
    }

    /** JSON drain status polled by the index page while maintenance is engaged. */
    public function status(): Response
    {
        $this->request->allowMethod('get');

        $session = (new MaintenanceService())->activeSession();
        $payload = $session === null
            ? ['active' => false]
            : ['active' => true] + (new QuiesceService())->status();

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
