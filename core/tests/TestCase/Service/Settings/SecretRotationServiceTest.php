<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Settings;

use App\Service\Settings\SecretCipher;
use App\Service\Settings\SecretRotationService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use RuntimeException;

/**
 * Encryption-key rotation of secret settings (Phase 6 Increment 3). Scoped to a test
 * namespace so it never touches the real (active-key) secrets, which it could not
 * decrypt with a test old key.
 */
class SecretRotationServiceTest extends TestCase
{
    private const NS = 'zztest_rot';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        ConnectionManager::get('default')->execute('DELETE FROM settings WHERE namespace = :ns', ['ns' => self::NS]);
    }

    private function seedSecret(string $key, string $plain, string $keyMaterial): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO settings (namespace, config_key, is_secret, value_encrypted) VALUES (:ns, :k, true, :v)',
            ['ns' => self::NS, 'k' => $key, 'v' => (new SecretCipher($keyMaterial))->encrypt($plain)],
        );
    }

    private function encryptedOf(string $key): string
    {
        return (string)ConnectionManager::get('default')->execute(
            'SELECT value_encrypted FROM settings WHERE namespace = :ns AND config_key = :k',
            ['ns' => self::NS, 'k' => $key],
        )->fetch('assoc')['value_encrypted'];
    }

    public function testRotatesScopedSecretToTheNewKey(): void
    {
        $this->seedSecret('k1', 'secret-value', 'old-key-AAA');

        $n = (new SecretRotationService())->rotate('old-key-AAA', 'new-key-BBB', self::NS);
        $this->assertSame(1, $n);

        // Now decryptable with the NEW key, no longer with the old.
        $enc = $this->encryptedOf('k1');
        $this->assertSame('secret-value', (new SecretCipher('new-key-BBB'))->decrypt($enc));
        $this->expectException(RuntimeException::class);
        (new SecretCipher('old-key-AAA'))->decrypt($enc);
    }

    public function testWrongOldKeyThrowsAndLeavesSecretUnchanged(): void
    {
        $this->seedSecret('k2', 'v', 'right-old');
        $original = $this->encryptedOf('k2');

        $threw = false;
        try {
            (new SecretRotationService())->rotate('WRONG-old', 'new-key', self::NS);
        } catch (RuntimeException) {
            $threw = true;
        }
        $this->assertTrue($threw);
        // Atomic: the failed decrypt-old happened before any write, so it is unchanged.
        $this->assertSame($original, $this->encryptedOf('k2'));
    }

    public function testIdenticalKeysIsNoOp(): void
    {
        $this->assertSame(0, (new SecretRotationService())->rotate('same', 'same', self::NS));
    }

    public function testEmptyOldKeyThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new SecretRotationService())->rotate('', 'new-key', self::NS);
    }
}
