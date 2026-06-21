<?php
declare(strict_types=1);

namespace App\Service\Settings;

use App\Audit\AuditLogger;
use App\Infrastructure\Db;
use RuntimeException;

/**
 * Encryption-key rotation for secret settings (Decision 164) — extracted from the
 * CLI {@see \App\Command\SecretCommand} so the maintenance critical-action handler
 * ({@see \App\Service\System\SecretRotateHandler}) can reuse it. Re-encrypts every
 * `is_secret` setting from the old key to the new key in ONE transaction, then
 * verifies each decrypts with the new key. Unlike the original bulk update, the
 * rotation is AUDITED. Runs privileged (the secrets span all tenants).
 */
class SecretRotationService
{
    public function __construct(private ?AuditLogger $audit = null)
    {
    }

    /**
     * Re-encrypts all secret settings from $oldKeyMaterial to $newKeyMaterial and
     * verifies the result. Returns the number rotated (0 = nothing to do). Throws if a
     * secret cannot be decrypted with the old key or re-verified with the new key (the
     * transaction is atomic, so a mid-rotation failure leaves the secrets unchanged).
     * $onlyNamespace scopes the rotation to one settings namespace (for testing —
     * the handler/CLI rotate globally).
     */
    public function rotate(string $oldKeyMaterial, string $newKeyMaterial, ?string $onlyNamespace = null): int
    {
        if (trim($oldKeyMaterial) === '') {
            throw new RuntimeException('Altes Schlüsselmaterial fehlt.');
        }
        if ($oldKeyMaterial === $newKeyMaterial) {
            return 0; // identical -> nothing to rotate
        }

        $oldCipher = new SecretCipher($oldKeyMaterial);
        $newCipher = new SecretCipher($newKeyMaterial);
        $conn = Db::privileged();

        $where = 'WHERE is_secret = true AND value_encrypted IS NOT NULL';
        $params = [];
        if ($onlyNamespace !== null) {
            $where .= ' AND namespace = :ns';
            $params['ns'] = $onlyNamespace;
        }

        $rows = $conn->execute("SELECT id, value_encrypted FROM settings $where", $params)->fetchAll('assoc');
        if ($rows === []) {
            return 0;
        }

        // Decrypt-old + encrypt-new up front; a wrong old key fails here, before any write.
        $reencrypted = [];
        foreach ($rows as $r) {
            $plain = $oldCipher->decrypt((string)$r['value_encrypted']);
            $reencrypted[(string)$r['id']] = $newCipher->encrypt($plain);
        }

        $conn->transactional(function () use ($conn, $reencrypted): void {
            foreach ($reencrypted as $id => $cipher) {
                $conn->execute(
                    'UPDATE settings SET value_encrypted = :v WHERE id = :id',
                    ['v' => $cipher, 'id' => $id],
                );
            }
        });

        // Verify every (in-scope) secret decrypts with the NEW key.
        $verifyRows = $conn->execute("SELECT value_encrypted FROM settings $where", $params)->fetchAll('assoc');
        foreach ($verifyRows as $r) {
            $newCipher->decrypt((string)$r['value_encrypted']);
        }

        $count = count($reencrypted);
        ($this->audit ??= new AuditLogger())->log('secret.rotate', 'settings', null, [
            'component' => 'core',
            'newValue' => ['rotated' => $count],
        ]);

        return $count;
    }
}
