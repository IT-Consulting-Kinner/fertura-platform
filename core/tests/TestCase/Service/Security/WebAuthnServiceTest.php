<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Security;

use App\Service\Security\Cbor;
use App\Service\Security\WebAuthnService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use RuntimeException;

/**
 * End-to-end test of the dependency-free WebAuthn (registration + assertion)
 * with a REAL EC P-256 key pair (OpenSSL): the complete crypto path
 * (CBOR → COSE → PEM → signature verification) without a browser. Also covers
 * the fail-closed paths: wrong challenge/origin/rpIdHash, tampered signature,
 * regressing sign counter (clone detection).
 */
class WebAuthnServiceTest extends TestCase
{
    private const RP_ID = 'fertura.test';
    private const ORIGIN = 'https://fertura.test';

    private string $userId;
    /**
     * @var \OpenSSLAsymmetricKey
     */
    private $key;
    private string $credentialId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->userId = (string)ConnectionManager::get('default')->execute(
            "INSERT INTO users (username, email, status) VALUES (:u, :e, 'active') RETURNING id",
            ['u' => 'zztest_wa_' . bin2hex(random_bytes(4)), 'e' => 'wa_' . bin2hex(random_bytes(4)) . '@zzwa.local'],
        )->fetch('assoc')['id'];
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        assert($key !== false);
        $this->key = $key;
        $this->credentialId = random_bytes(16);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        ConnectionManager::get('default')->execute("DELETE FROM users WHERE email LIKE '%@zzwa.local'");
    }

    // ---- Browser simulation -----------------------------------------------------

    private function clientDataJson(string $type, string $challenge, string $origin = self::ORIGIN): string
    {
        return (string)json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin]);
    }

    private function authData(int $flags, int $signCount, bool $withCredential, string $rpId = self::RP_ID): string
    {
        $out = hash('sha256', $rpId, true) . chr($flags) . pack('N', $signCount);
        if ($withCredential) {
            $details = openssl_pkey_get_details($this->key);
            $coseKey = Cbor::encode([
                1 => 2, // kty EC2
                3 => -7, // alg ES256
                -1 => 1, // crv P-256
                // Left-pad the raw EC coordinates to 32 bytes (openssl drops a
                // leading zero ~1/256 of the time → the COSE key would be 31 bytes and invalid).
                -2 => str_pad((string)$details['ec']['x'], 32, "\x00", STR_PAD_LEFT),
                -3 => str_pad((string)$details['ec']['y'], 32, "\x00", STR_PAD_LEFT),
            ]);
            $out .= str_repeat("\x00", 16) // aaguid
                . pack('n', strlen($this->credentialId)) . $this->credentialId
                . $coseKey;
        }

        return $out;
    }

    private function register(WebAuthnService $service, string $challenge): void
    {
        $attestation = Cbor::encode([
            'fmt' => 'none',
            'attStmt' => [],
            'authData' => $this->authData(0x41, 0, true), // UP + AT
        ]);
        $service->register(
            $this->userId,
            WebAuthnService::b64uEncode($this->clientDataJson('webauthn.create', $challenge)),
            WebAuthnService::b64uEncode($attestation),
            $challenge,
            self::RP_ID,
            'Test-Key',
        );
    }

    /** @return array{0:string,1:string,2:string} [clientDataB64u, authDataB64u, signatureB64u] */
    private function assertion(string $challenge, int $signCount): array
    {
        $clientData = $this->clientDataJson('webauthn.get', $challenge);
        $authData = $this->authData(0x01, $signCount, false); // UP
        openssl_sign($authData . hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            WebAuthnService::b64uEncode($clientData),
            WebAuthnService::b64uEncode($authData),
            WebAuthnService::b64uEncode($signature),
        ];
    }

    // ---- Tests --------------------------------------------------------------------

    public function testRegisterAndAssertRoundtrip(): void
    {
        $service = new WebAuthnService();
        $this->register($service, WebAuthnService::challenge());
        $this->assertTrue($service->hasCredentials($this->userId));
        $this->assertSame('Test-Key', $service->credentials($this->userId)[0]['label']);

        $challenge = WebAuthnService::challenge();
        [$cd, $ad, $sig] = $this->assertion($challenge, 5);
        $this->assertTrue($service->verifyAssertion(
            $this->userId,
            WebAuthnService::b64uEncode($this->credentialId),
            $cd,
            $ad,
            $sig,
            $challenge,
            self::RP_ID,
        ));
        // Counter persisted.
        $this->assertSame(5, (int)ConnectionManager::get('default')->execute(
            'SELECT sign_count FROM user_webauthn_credentials WHERE user_id = :u',
            ['u' => $this->userId],
        )->fetch('assoc')['sign_count']);
    }

    public function testCounterRegressionRejected(): void
    {
        $service = new WebAuthnService();
        $this->register($service, WebAuthnService::challenge());
        $challenge = WebAuthnService::challenge();
        [$cd, $ad, $sig] = $this->assertion($challenge, 10);
        $this->assertTrue($service->verifyAssertion(
            $this->userId,
            WebAuthnService::b64uEncode($this->credentialId),
            $cd,
            $ad,
            $sig,
            $challenge,
            self::RP_ID,
        ));

        // Clone suspicion: counter runs backwards -> rejected.
        $challenge2 = WebAuthnService::challenge();
        [$cd2, $ad2, $sig2] = $this->assertion($challenge2, 7);
        $this->assertFalse($service->verifyAssertion(
            $this->userId,
            WebAuthnService::b64uEncode($this->credentialId),
            $cd2,
            $ad2,
            $sig2,
            $challenge2,
            self::RP_ID,
        ));
    }

    public function testTamperedSignatureAndWrongContextRejected(): void
    {
        $service = new WebAuthnService();
        $this->register($service, WebAuthnService::challenge());
        $credId = WebAuthnService::b64uEncode($this->credentialId);

        $challenge = WebAuthnService::challenge();
        [$cd, $ad, $sig] = $this->assertion($challenge, 3);

        // Tampered signature.
        $bad = WebAuthnService::b64uEncode(strrev(WebAuthnService::b64uDecode($sig)));
        $this->assertFalse($service->verifyAssertion($this->userId, $credId, $cd, $ad, $bad, $challenge, self::RP_ID));

        // Wrong challenge.
        $this->assertFalse($service->verifyAssertion($this->userId, $credId, $cd, $ad, $sig, WebAuthnService::challenge(), self::RP_ID));

        // Wrong rpId (rpIdHash does not match).
        $this->assertFalse($service->verifyAssertion($this->userId, $credId, $cd, $ad, $sig, $challenge, 'evil.example'));

        // Unknown credential ID.
        $this->assertFalse($service->verifyAssertion($this->userId, WebAuthnService::b64uEncode('nope'), $cd, $ad, $sig, $challenge, self::RP_ID));
    }

    public function testRegisterRejectsWrongOriginAndChallenge(): void
    {
        $service = new WebAuthnService();
        $challenge = WebAuthnService::challenge();
        $attestation = WebAuthnService::b64uEncode(Cbor::encode([
            'fmt' => 'none', 'attStmt' => [], 'authData' => $this->authData(0x41, 0, true),
        ]));

        // Foreign origin.
        try {
            $service->register(
                $this->userId,
                WebAuthnService::b64uEncode($this->clientDataJson('webauthn.create', $challenge, 'https://evil.example')),
                $attestation,
                $challenge,
                self::RP_ID,
            );
            $this->fail('Fremde Origin muss abgewiesen werden.');
        } catch (RuntimeException) {
            // expected
        }

        // Wrong challenge.
        $this->expectException(RuntimeException::class);
        $service->register(
            $this->userId,
            WebAuthnService::b64uEncode($this->clientDataJson('webauthn.create', WebAuthnService::challenge())),
            $attestation,
            $challenge,
            self::RP_ID,
        );
    }

    public function testDeleteRemovesCredential(): void
    {
        $service = new WebAuthnService();
        $this->register($service, WebAuthnService::challenge());
        $rowId = $service->credentials($this->userId)[0]['id'];

        $this->assertTrue($service->delete($this->userId, $rowId));
        $this->assertFalse($service->hasCredentials($this->userId));
        $this->assertFalse($service->delete($this->userId, $rowId)); // idempotent
    }
}
