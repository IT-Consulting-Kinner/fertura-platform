<?php
declare(strict_types=1);

namespace App\Service\Security;

use RuntimeException;
use Throwable;

/**
 * Ed25519-Signatur (libsodium), Entscheidung Step 8.
 * Schlüssel/Signaturen werden base64-kodiert ausgetauscht.
 */
class Signer
{
    /** @return array{public: string, secret: string} */
    public static function generateKeypair(): array
    {
        $kp = sodium_crypto_sign_keypair();

        return [
            'public' => base64_encode(sodium_crypto_sign_publickey($kp)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($kp)),
        ];
    }

    public function sign(string $message, string $secretKeyB64): string
    {
        $sk = base64_decode($secretKeyB64, true);
        if ($sk === false) {
            throw new RuntimeException('Ungültiger Secret-Key.');
        }

        return base64_encode(sodium_crypto_sign_detached($message, $sk));
    }

    public function verify(string $message, string $signatureB64, string $publicKeyB64): bool
    {
        $sig = base64_decode($signatureB64, true);
        $pk = base64_decode($publicKeyB64, true);
        if ($sig === false || $pk === false) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($sig, $message, $pk);
        } catch (Throwable) {
            return false;
        }
    }
}
