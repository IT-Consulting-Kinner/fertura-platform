<?php
declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\Uuid;
use App\Service\Security\MfaService;
use App\Service\Security\Totp;
use App\Service\Security\WebAuthnService;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;
use Throwable;

/**
 * Self-service MFA management (TOTP) for the AUTHENTICATED user:
 * status, two-step setup (show secret → confirm code → show recovery
 * codes once) and deactivation (re-authenticated via a valid code).
 *
 * The pending secret lives ONLY in the session until confirmation (never
 * unconfirmed in the DB); it is persisted encrypted in the MfaService.
 */
class MfaController extends AppController
{
    public function index(): void
    {
        $mfa = new MfaService();
        $userId = $this->userId();
        $this->set('enabled', $mfa->enabled($userId));
        $this->set('recoveryLeft', $mfa->recoveryCodesLeft($userId));
        $this->set('required', $mfa->required());
        $this->set('passkeys', (new WebAuthnService())->credentials($userId));
    }

    /**
     * JSON options for `navigator.credentials.create()` (passkey registration).
     * Precondition: TOTP is set up (recovery codes exist) — the passkey is the
     * more convenient alternative, not the only second factor (no lockout if the
     * device is lost).
     */
    public function passkeyOptions(): Response
    {
        if (!(new MfaService())->enabled($this->userId())) {
            return $this->response->withStatus(409)->withType('application/json')
                ->withStringBody((string)json_encode(['error' => 'totp_required']));
        }
        $service = new WebAuthnService();
        $challenge = WebAuthnService::challenge();
        $this->request->getSession()->write('Mfa.passkey_challenge', $challenge);
        $options = $service->registrationOptions($this->userId(), $this->accountLabel(), $this->rpId(), $challenge);

        return $this->response->withType('application/json')->withStringBody((string)json_encode($options));
    }

    /** Completes the passkey registration (form POST with JS-populated fields). */
    public function passkeyRegister(): ?Response
    {
        $this->request->allowMethod('post');
        $session = $this->request->getSession();
        $challenge = (string)($session->read('Mfa.passkey_challenge') ?? '');
        $session->delete('Mfa.passkey_challenge');
        if ($challenge === '' || !(new MfaService())->enabled($this->userId())) {
            $this->Flash->error(__('flash.mfa.setup_restart'));

            return $this->redirect(['action' => 'index']);
        }
        try {
            (new WebAuthnService())->register(
                $this->userId(),
                (string)$this->request->getData('client_data'),
                (string)$this->request->getData('attestation'),
                $challenge,
                $this->rpId(),
                (string)$this->request->getData('label'),
            );
            $this->Flash->success(__('flash.mfa.passkey_added'));
        } catch (Throwable) {
            $this->Flash->error(__('flash.mfa.passkey_failed'));
        }

        return $this->redirect(['action' => 'index']);
    }

    public function passkeyDelete(string $id): ?Response
    {
        $this->request->allowMethod('post');
        if ((new WebAuthnService())->delete($this->userId(), $id)) {
            $this->Flash->success(__('flash.mfa.passkey_deleted'));
        } else {
            $this->Flash->error(__('flash.mfa.passkey_failed'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /** Relying party ID = request host without port (WebAuthn origin binding). */
    private function rpId(): string
    {
        return (string)$this->request->getUri()->getHost();
    }

    /** Type-safe user ID of the authenticated identity. */
    private function userId(): string
    {
        $identifier = $this->identity()?->getIdentifier();

        return is_string($identifier) ? $identifier : '';
    }

    /** Step 1: generate the secret and display it for confirmation (otpauth + manual). */
    public function setup(): Response
    {
        $this->request->allowMethod('post');
        $secret = Totp::generateSecret();
        $this->request->getSession()->write('Mfa.setup_secret', $secret);

        $this->set('secret', $secret);
        $this->set('otpauthUri', Totp::provisioningUri($secret, $this->accountLabel(), MfaService::ISSUER));

        return $this->render('setup');
    }

    /** Step 2: confirm the code against the pending secret -> activate. */
    public function confirm(): ?Response
    {
        $this->request->allowMethod('post');
        $session = $this->request->getSession();
        $secret = (string)($session->read('Mfa.setup_secret') ?? '');
        if ($secret === '') {
            $this->Flash->error(__('flash.mfa.setup_restart'));

            return $this->redirect(['action' => 'index']);
        }

        $userId = $this->userId();
        $codes = (new MfaService())->confirmEnrollment($userId, $secret, (string)$this->request->getData('code'));
        if ($codes === null) {
            $this->Flash->error(__('flash.mfa.invalid'));
            // Show the same secret again (the app may already be set up).
            $this->set('secret', $secret);
            $this->set('otpauthUri', Totp::provisioningUri($secret, $this->accountLabel(), MfaService::ISSUER));

            return $this->render('setup');
        }

        $session->delete('Mfa.setup_secret');
        $this->set('recoveryCodes', $codes); // one-time display

        return $this->render('recovery');
    }

    /** Deactivate — re-authenticated via a valid TOTP/recovery code. */
    public function disable(): ?Response
    {
        $this->request->allowMethod('post');
        $userId = $this->userId();
        $mfa = new MfaService();
        if (!$mfa->verify($userId, (string)$this->request->getData('code'))) {
            $this->Flash->error(__('flash.mfa.invalid'));

            return $this->redirect(['action' => 'index']);
        }
        $mfa->disable($userId);
        $this->Flash->success(__('flash.mfa.disabled'));

        return $this->redirect(['action' => 'index']);
    }

    /** Account label for the otpauth URI (username from the DB, type-safe). */
    private function accountLabel(): string
    {
        $identifier = $this->identity()?->getIdentifier();
        $userId = is_string($identifier) ? $identifier : '';
        if (!Uuid::isValid($userId)) {
            return 'user';
        }
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        $row = $conn->execute(
            'SELECT username FROM users WHERE id = :id',
            ['id' => $userId],
        )->fetch('assoc');

        return $row !== false ? (string)$row['username'] : 'user';
    }
}
