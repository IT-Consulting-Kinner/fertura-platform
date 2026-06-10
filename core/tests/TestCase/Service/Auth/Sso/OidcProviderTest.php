<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Auth\Sso;

use App\Service\Auth\Sso\OidcProvider;
use App\Service\Auth\Sso\SsoException;
use Cake\TestSuite\TestCase;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

/**
 * Test der OIDC-ID-Token-Validierung (P06) gegen ein echtes, lokal erzeugtes
 * Schlüsselpaar (web-token) — Signatur + iss/aud/exp/nonce, ohne Netzwerk.
 */
class OidcProviderTest extends TestCase
{
    private const ISS = 'https://idp.example.com';
    private const CID = 'client-123';

    private JWK $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'k1']);
    }

    /** @param array<string,mixed> $claims */
    private function token(array $claims, ?JWK $signWith = null): string
    {
        $builder = new JWSBuilder(new AlgorithmManager([new RS256()]));
        $jws = $builder->create()
            ->withPayload((string)json_encode($claims))
            ->addSignature($signWith ?? $this->key, ['alg' => 'RS256', 'kid' => 'k1'])
            ->build();

        return (new CompactSerializer())->serialize($jws, 0);
    }

    private function jwks(): JWKSet
    {
        return new JWKSet([$this->key->toPublic()]);
    }

    private function baseClaims(): array
    {
        return [
            'iss' => self::ISS,
            'aud' => self::CID,
            'sub' => 'user-789',
            'email' => 'sso@example.com',
            'exp' => time() + 300,
            'nonce' => 'n-abc',
        ];
    }

    public function testValidTokenPasses(): void
    {
        $claims = (new OidcProvider())->validateIdToken(
            $this->token($this->baseClaims()),
            $this->jwks(),
            self::ISS,
            self::CID,
            'n-abc',
        );
        $this->assertSame('user-789', $claims['sub']);
        $this->assertSame('sso@example.com', $claims['email']);
    }

    public function testWrongAudienceRejected(): void
    {
        $this->expectException(SsoException::class);
        $this->expectExceptionMessageMatches('/aud/');
        (new OidcProvider())->validateIdToken($this->token($this->baseClaims()), $this->jwks(), self::ISS, 'other', 'n-abc');
    }

    public function testExpiredRejected(): void
    {
        $claims = ['exp' => time() - 3600] + $this->baseClaims();
        $this->expectException(SsoException::class);
        $this->expectExceptionMessageMatches('/abgelaufen/');
        (new OidcProvider())->validateIdToken($this->token($claims), $this->jwks(), self::ISS, self::CID, 'n-abc');
    }

    public function testNonceMismatchRejected(): void
    {
        $this->expectException(SsoException::class);
        $this->expectExceptionMessageMatches('/nonce/');
        (new OidcProvider())->validateIdToken($this->token($this->baseClaims()), $this->jwks(), self::ISS, self::CID, 'different');
    }

    public function testMultiAudienceRequiresMatchingAzp(): void
    {
        $claims = ['aud' => [self::CID, 'other-client'], 'azp' => self::CID] + $this->baseClaims();
        $ok = (new OidcProvider())->validateIdToken($this->token($claims), $this->jwks(), self::ISS, self::CID, 'n-abc');
        $this->assertSame('user-789', $ok['sub']);

        $bad = ['aud' => [self::CID, 'other-client'], 'azp' => 'other-client'] + $this->baseClaims();
        $this->expectException(SsoException::class);
        $this->expectExceptionMessageMatches('/azp/');
        (new OidcProvider())->validateIdToken($this->token($bad), $this->jwks(), self::ISS, self::CID, 'n-abc');
    }

    public function testForeignSignatureRejected(): void
    {
        $attacker = JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'k1']);
        $this->expectException(SsoException::class);
        $this->expectExceptionMessageMatches('/Signatur/');
        (new OidcProvider())->validateIdToken(
            $this->token($this->baseClaims(), $attacker),
            $this->jwks(),
            self::ISS,
            self::CID,
            'n-abc',
        );
    }
}
