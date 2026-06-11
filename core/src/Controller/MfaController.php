<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Security\MfaService;
use App\Service\Security\Totp;

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
