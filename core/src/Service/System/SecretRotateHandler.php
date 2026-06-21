<?php
declare(strict_types=1);

namespace App\Service\System;

use App\Infrastructure\Db;
use App\Service\Settings\SecretCipher;
use App\Service\Settings\SecretRotationService;
use Cake\Core\Configure;
use RuntimeException;
use Throwable;
use function Cake\Core\env;

/**
 * The secret-rotation critical action — Phase 6 (Increment 3).
 *
 * Re-encrypts every secret setting from the OLD key to the active key. The old key
 * material is read from the environment (SECURITY_ENCRYPTION_KEY_OLD[_FILE]) — NOT
 * the payload — so it is never persisted in plaintext in the DB (mirrors the backup
 * password). The new (active) key is Security.encryptionKey, already deployed.
 * verify() round-trips a secret with the active key. rollback() has NO clean
 * action-own undo: re-encrypting back to the old key would leave the secrets out of
 * sync with the active env key, so once the rotation has committed we escalate to
 * needs_manual_restore (operator restores the pre-action backup / fixes the key
 * deployment). The rotation itself is atomic, so an execute failure leaves the
 * secrets unchanged and nothing to undo.
 */
class SecretRotateHandler implements CriticalActionHandler
{
    private bool $rotated = false;

    public function __construct(private SecretRotationService $rotation = new SecretRotationService())
    {
    }

    public function type(): string
    {
        return 'secret_rotate';
    }

    public function execute(array $payload): void
    {
        $old = $this->oldKeyMaterial();
        if ($old === '') {
            throw new RuntimeException(
                'Altes Schlüsselmaterial nicht bereitgestellt (SECURITY_ENCRYPTION_KEY_OLD[_FILE]).',
            );
        }
        $new = (string)(Configure::read('Security.encryptionKey') ?? Configure::read('Security.salt') ?? '');
        if ($new === '') {
            throw new RuntimeException('Kein aktiver Verschlüsselungsschlüssel konfiguriert.');
        }
        $this->rotation->rotate($old, $new);
        $this->rotated = true;
    }

    public function verify(array $payload): array
    {
        $row = Db::privileged()->execute(
            "SELECT value_encrypted FROM settings WHERE is_secret = true AND value_encrypted IS NOT NULL LIMIT 1",
        )->fetch('assoc');
        if ($row === false) {
            return ['ok' => true, 'reason' => null]; // no secrets -> trivially consistent
        }
        try {
            (new SecretCipher())->decrypt((string)$row['value_encrypted']);

            return ['ok' => true, 'reason' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => 'Secret nicht mit aktivem Schlüssel entschlüsselbar: ' . $e->getMessage()];
        }
    }

    public function rollback(array $payload): void
    {
        if ($this->rotated) {
            throw new RuntimeException(
                'Secret-Rotation hat keinen aktionseigenen Undo — Pre-Action-Backup wiederherstellen '
                . 'bzw. Schlüssel-Deployment prüfen.',
            );
        }
        // execute() failed before the (atomic) rotation committed -> nothing changed.
    }

    private function oldKeyMaterial(): string
    {
        $file = (string)env('SECURITY_ENCRYPTION_KEY_OLD_FILE');
        if ($file !== '' && is_file($file)) {
            return trim((string)file_get_contents($file));
        }

        return (string)env('SECURITY_ENCRYPTION_KEY_OLD');
    }
}
