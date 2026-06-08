<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Security;

use App\Service\Security\PackageVerificationException;
use App\Service\Security\PackageVerifier;
use App\Service\Security\Signer;
use App\Service\Security\TrustChain;
use App\Service\Security\TrustStore;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Integrationstest der Vertrauenskette (Kap. 24.9.2) gegen die Test-DB:
 * Root signiert Publisher-Zertifikat, Publisher signiert ein Paket. Geprüft
 * werden gültige Signatur, Manipulation, Widerruf und Gültigkeitsfenster —
 * der komplette echte Pfad über Signer/TrustStore/TrustChain/PackageVerifier.
 */
class TrustChainTest extends TestCase
{
    private string $dir = '';
    private string $rootId = '';
    private string $pubId = '';
    private string $rootSecret = '';
    private string $pubSecret = '';
    private const PUBLISHER = 'Fertura Test Verlag';

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = bin2hex(random_bytes(5));
        $this->rootId = 'ztest-root-' . $suffix;
        $this->pubId = 'ztest-pub-' . $suffix;

        // Echte Ed25519-Schlüsselpaare.
        $root = Signer::generateKeypair();
        $pub = Signer::generateKeypair();
        $this->rootSecret = $root['secret'];
        $this->pubSecret = $pub['secret'];

        $signer = new Signer();
        $trust = new TrustStore();

        // Root-Anker (out-of-band vertraut).
        $trust->addAnchor($this->rootId, $root['public'], 'root');

        // Publisher-Zertifikat: Root signiert die kanonische Schlüsselaussage.
        $statement = TrustChain::keyStatement($this->pubId, $pub['public'], self::PUBLISHER);
        $keySignature = $signer->sign($statement, $this->rootSecret);
        $trust->addAnchor(
            $this->pubId,
            $pub['public'],
            'publisher',
            self::PUBLISHER,
            $this->rootId,
            $keySignature,
        );

        // Minimales Paketverzeichnis + Signatur über den Paket-Digest.
        $this->dir = sys_get_temp_dir() . '/fertura_trusttest_' . $suffix;
        mkdir($this->dir . '/src', 0o775, true);
        file_put_contents($this->dir . '/manifest.json', json_encode([
            'id' => 'ztest_module', 'name' => 'Trust Test', 'version' => '1.0.0',
            'type' => 'main', 'publisher' => self::PUBLISHER,
        ]));
        file_put_contents($this->dir . '/src/Code.php', "<?php // payload\n");
        $this->writeSignature($this->pubId, $this->pubSecret);
    }

    protected function tearDown(): void
    {
        $conn = ConnectionManager::get('default');
        foreach ([$this->rootId, $this->pubId] as $kid) {
            $conn->execute('DELETE FROM trust_anchors WHERE key_id = :k', ['k' => $kid]);
            $conn->execute('DELETE FROM revoked_keys WHERE key_id = :k', ['k' => $kid]);
        }
        $this->rrmdir($this->dir);
        parent::tearDown();
    }

    public function testValidPublisherChainVerifies(): void
    {
        $result = (new PackageVerifier())->verify($this->dir, self::PUBLISHER);
        $this->assertSame($this->pubId, $result['key_id']);
    }

    public function testTamperedContentRejected(): void
    {
        // Code nach dem Signieren verändern -> Digest weicht ab.
        file_put_contents($this->dir . '/src/Code.php', "<?php // TAMPERED\n");
        $this->expectException(PackageVerificationException::class);
        $this->expectExceptionMessageMatches('/manipuliert|ungültig/i');
        (new PackageVerifier())->verify($this->dir, self::PUBLISHER);
    }

    public function testPublisherMismatchRejected(): void
    {
        $this->expectException(PackageVerificationException::class);
        $this->expectExceptionMessageMatches('/Publisher/i');
        (new PackageVerifier())->verify($this->dir, 'Falscher Verlag');
    }

    public function testRevokedPublisherRejected(): void
    {
        (new TrustStore())->revokeKey($this->pubId, 'Test-Widerruf');
        $this->expectException(PackageVerificationException::class);
        $this->expectExceptionMessageMatches('/widerrufen/i');
        (new PackageVerifier())->verify($this->dir, self::PUBLISHER);
    }

    public function testRevokedRootBreaksChain(): void
    {
        // Root-Widerruf entzieht nachträglich allen darunter signierten Paketen
        // das Vertrauen (Defense-in-Depth, Kap. 24.9.2).
        (new TrustStore())->revokeKey($this->rootId, 'Root kompromittiert');
        $this->expectException(PackageVerificationException::class);
        $this->expectExceptionMessageMatches('/Vertrauenskette|widerrufen/i');
        (new PackageVerifier())->verify($this->dir, self::PUBLISHER);
    }

    public function testExpiredAnchorRejected(): void
    {
        ConnectionManager::get('default')->execute(
            "UPDATE trust_anchors SET valid_from = NULL, valid_to = now() - interval '1 day' WHERE key_id = :k",
            ['k' => $this->pubId],
        );
        $this->expectException(PackageVerificationException::class);
        $this->expectExceptionMessageMatches('/abgelaufen/i');
        (new PackageVerifier())->verify($this->dir, self::PUBLISHER);
    }

    public function testNotYetValidAnchorRejected(): void
    {
        ConnectionManager::get('default')->execute(
            "UPDATE trust_anchors SET valid_from = now() + interval '1 day', valid_to = NULL WHERE key_id = :k",
            ['k' => $this->pubId],
        );
        $this->expectException(PackageVerificationException::class);
        $this->expectExceptionMessageMatches('/noch nicht gültig/i');
        (new PackageVerifier())->verify($this->dir, self::PUBLISHER);
    }

    private function writeSignature(string $keyId, string $secret): void
    {
        $digest = (new PackageVerifier())->packageDigest($this->dir);
        $signature = (new Signer())->sign($digest, $secret);
        file_put_contents(
            $this->dir . '/signature.json',
            json_encode(['key_id' => $keyId, 'signature' => $signature]),
        );
    }

    private function rrmdir(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
