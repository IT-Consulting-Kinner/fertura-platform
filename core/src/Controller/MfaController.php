<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Security\MfaService;
use App\Service\Security\Totp;
use App\Service\Security\WebAuthnService;

/**
 * Self-Service-MFA-Verwaltung (TOTP) für den ANGEMELDETEN Benutzer:
 * Status, zweistufige Einrichtung (Secret anzeigen → Code bestätigen →
 * Recovery-Codes einmalig zeigen) und Deaktivierung (re-authentifiziert
 * per gültigem Code).
 *
 * Das Pending-Secret lebt bis zur Bestätigung NUR in der Session (nie
 * unbestätigt in der DB); persistiert wird verschlüsselt im MfaService.
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
     * JSON-Optionen für `navigator.credentials.create()` (Passkey-Registrierung).
     * Voraussetzung: TOTP ist eingerichtet (Recovery-Codes existieren) — der
     * Passkey ist die bequemere Alternative, nicht der einzige zweite Faktor
     * (keine Aussperrung bei Geräteverlust).
     */
    public function passkeyOptions(): \Cake\Http\Response
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

    /** Schließt die Passkey-Registrierung ab (Formular-POST mit JS-befüllten Feldern). */
    public function passkeyRegister(): ?\Cake\Http\Response
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
        } catch (\Throwable) {
            $this->Flash->error(__('flash.mfa.passkey_failed'));
        }

        return $this->redirect(['action' => 'index']);
    }

    public function passkeyDelete(string $id): ?\Cake\Http\Response
    {
        $this->request->allowMethod('post');
        if ((new WebAuthnService())->delete($this->userId(), $id)) {
            $this->Flash->success(__('flash.mfa.passkey_deleted'));
        } else {
            $this->Flash->error(__('flash.mfa.passkey_failed'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /** Relying-Party-ID = Request-Host ohne Port (WebAuthn-Origin-Bindung). */
    private function rpId(): string
    {
        return (string)$this->request->getUri()->getHost();
    }

    /** Typ-sichere Benutzer-ID der angemeldeten Identität. */
    private function userId(): string
    {
        $identifier = $this->identity()?->getIdentifier();

        return is_string($identifier) ? $identifier : '';
    }

    /** Schritt 1: Secret erzeugen und zur Bestätigung anzeigen (otpauth + manuell). */
    public function setup(): \Cake\Http\Response
    {
        $this->request->allowMethod('post');
        $secret = Totp::generateSecret();
        $this->request->getSession()->write('Mfa.setup_secret', $secret);

        $this->set('secret', $secret);
        $this->set('otpauthUri', Totp::provisioningUri($secret, $this->accountLabel(), MfaService::ISSUER));

        return $this->render('setup');
    }

    /** Schritt 2: Code gegen das Pending-Secret bestätigen -> aktivieren. */
    public function confirm(): ?\Cake\Http\Response
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
            // Gleiches Secret erneut anzeigen (App ist ggf. schon eingerichtet).
            $this->set('secret', $secret);
            $this->set('otpauthUri', Totp::provisioningUri($secret, $this->accountLabel(), MfaService::ISSUER));

            return $this->render('setup');
        }

        $session->delete('Mfa.setup_secret');
        $this->set('recoveryCodes', $codes); // einmalige Anzeige

        return $this->render('recovery');
    }

    /** Deaktivieren — re-authentifiziert per gültigem TOTP-/Recovery-Code. */
    public function disable(): ?\Cake\Http\Response
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

    /** Konto-Label für die otpauth-URI (Benutzername aus der DB, typ-sicher). */
    private function accountLabel(): string
    {
        $identifier = $this->identity()?->getIdentifier();
        $userId = is_string($identifier) ? $identifier : '';
        if (!\App\Infrastructure\Uuid::isValid($userId)) {
            return 'user';
        }
        /** @var \Cake\Database\Connection $conn */
        $conn = \Cake\Datasource\ConnectionManager::get('default');
        $row = $conn->execute(
            'SELECT username FROM users WHERE id = :id',
            ['id' => $userId],
        )->fetch('assoc');

        return $row !== false ? (string)$row['username'] : 'user';
    }
}
