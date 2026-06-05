<?php
declare(strict_types=1);

namespace App\Service\Settings;

use Cake\Core\Configure;
use RuntimeException;

/**
 * Authentifizierte Verschlüsselung für geheime Settings (AES-256-GCM,
 * Entscheidung 159). Der Schlüssel stammt aus der Infrastruktur-Konfiguration
 * (config/app.php -> Security.encryptionKey, gespeist aus env), NICHT aus der DB
 * -> ein DB-Leak allein gibt die Geheimnisse nicht preis.
 *
 * Format der Ablage: base64( iv[12] || tag[16] || ciphertext ).
 */
class SecretCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    private string $key;

    public function __construct(?string $keyMaterial = null)
    {
        $material = $keyMaterial
            ?? Configure::read('Security.encryptionKey')
            ?? Configure::read('Security.salt');
        if (empty($material)) {
            throw new RuntimeException('Kein Verschlüsselungsschlüssel konfiguriert (Security.encryptionKey).');
        }
        // Auf 32 Byte normalisieren (AES-256).
        $this->key = hash('sha256', (string)$material, true);
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Verschlüsselung fehlgeschlagen.');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN) {
            throw new RuntimeException('Ungültiges Chiffrat.');
        }
        $iv = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
        $cipher = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plain = openssl_decrypt($cipher, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Entschlüsselung fehlgeschlagen (falscher Schlüssel oder Manipulation).');
        }

        return $plain;
    }
}
