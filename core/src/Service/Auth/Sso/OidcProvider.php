<?php
declare(strict_types=1);

namespace App\Service\Auth\Sso;

use App\Service\Cache\CacheStore;
use App\Service\Http\EgressClient;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\ES384;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Throwable;

/**
 * OpenID-Connect-Provider (Programm Tier-1, P06): Authorization-Code-Flow mit
 * **PKCE**, Discovery + JWKS-Validierung des ID-Tokens (web-token/jwt-library).
 *
 * Netzzugriffe laufen über den gehärteten Egress (P01); Discovery/JWKS werden
 * gecacht (P02). Die ID-Token-Validierung ({@see validateIdToken}) ist rein und
 * damit ohne Netzwerk testbar.
 */
class OidcProvider
{
    public function __construct(
        private ?EgressClient $egress = null,
        private ?CacheStore $cache = null,
    ) {
        $this->egress ??= new EgressClient();
        $this->cache ??= new CacheStore('_app_');
    }

    /**
     * Baut die Authorization-URL und die im Flow zu speichernden Geheimnisse.
     *
     * @param array<string,mixed> $provider
     * @return array{url:string,state:string,nonce:string,code_verifier:string}
     */
    public function authorizationData(array $provider, string $redirectUri): array
    {
        $disc = $this->discovery($provider);
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $verifier = $this->base64url(random_bytes(32));
        $challenge = $this->base64url(hash('sha256', $verifier, true));

        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => (string)($provider['config']['client_id'] ?? ''),
            'redirect_uri' => $redirectUri,
            'scope' => (string)($provider['config']['scopes'] ?? 'openid email profile'),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        $authEndpoint = (string)$disc['authorization_endpoint'];
        $url = $authEndpoint . (str_contains($authEndpoint, '?') ? '&' : '?') . $params;

        return ['url' => $url, 'state' => $state, 'nonce' => $nonce, 'code_verifier' => $verifier];
    }

    /**
     * Tauscht den Code gegen Tokens, validiert das ID-Token und liefert die
     * Identitätsdaten.
     *
     * @param array<string,mixed> $provider
     * @return array{sub:string,email:string,first:?string,last:?string}
     */
    public function complete(array $provider, string $code, string $codeVerifier, string $expectedNonce, string $redirectUri): array
    {
        $disc = $this->discovery($provider);
        $resp = $this->egress->request('POST', (string)$disc['token_endpoint'], [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            'data' => http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'client_id' => (string)($provider['config']['client_id'] ?? ''),
                'client_secret' => (string)($provider['secret'] ?? ''),
                'code_verifier' => $codeVerifier,
            ]),
        ]);
        $tok = $resp->json();
        if (!is_array($tok) || empty($tok['id_token'])) {
            throw new SsoException('OIDC-Token-Austausch fehlgeschlagen.');
        }

        $claims = $this->validateIdToken(
            (string)$tok['id_token'],
            $this->jwks($disc),
            rtrim((string)($provider['config']['issuer'] ?? ''), '/'),
            (string)($provider['config']['client_id'] ?? ''),
            $expectedNonce,
        );

        return [
            'sub' => (string)($claims['sub'] ?? ''),
            'email' => (string)($claims['email'] ?? ''),
            'first' => isset($claims['given_name']) ? (string)$claims['given_name'] : null,
            'last' => isset($claims['family_name']) ? (string)$claims['family_name'] : null,
        ];
    }

    /**
     * Validiert ein ID-Token gegen ein JWKSet (Signatur + iss/aud/exp/nonce).
     * Rein/ohne Netzwerk -> testbar.
     *
     * @return array<string,mixed> die geprüften Claims
     */
    public function validateIdToken(string $idToken, JWKSet $jwks, string $issuer, string $clientId, ?string $expectedNonce): array
    {
        try {
            $jws = (new CompactSerializer())->unserialize($idToken);
        } catch (Throwable) {
            throw new SsoException('ID-Token nicht lesbar.');
        }
        $verifier = new JWSVerifier(new AlgorithmManager([
            new RS256(), new RS384(), new RS512(), new PS256(), new ES256(), new ES384(),
        ]));
        if (!$verifier->verifyWithKeySet($jws, $jwks, 0)) {
            throw new SsoException('ID-Token-Signatur ungültig.');
        }
        $claims = json_decode((string)$jws->getPayload(), true);
        if (!is_array($claims)) {
            throw new SsoException('ID-Token-Payload ungültig.');
        }
        if (($claims['iss'] ?? '') !== $issuer) {
            throw new SsoException('ID-Token: iss stimmt nicht.');
        }
        $aud = $claims['aud'] ?? '';
        $audOk = is_array($aud) ? in_array($clientId, $aud, true) : $aud === $clientId;
        if (!$audOk) {
            throw new SsoException('ID-Token: aud stimmt nicht.');
        }
        if (!isset($claims['exp']) || (int)$claims['exp'] < time() - 60) {
            throw new SsoException('ID-Token abgelaufen.');
        }
        if ($expectedNonce !== null && ($claims['nonce'] ?? null) !== $expectedNonce) {
            throw new SsoException('ID-Token: nonce stimmt nicht.');
        }

        return $claims;
    }

    /**
     * @param array<string,mixed> $provider
     * @return array<string,mixed>
     */
    private function discovery(array $provider): array
    {
        $issuer = rtrim((string)($provider['config']['issuer'] ?? ''), '/');
        if ($issuer === '') {
            throw new SsoException('OIDC-Provider ohne issuer.');
        }
        $data = $this->cache->remember('oidc.disc.' . md5($issuer), function () use ($issuer): array {
            $resp = $this->egress->get($issuer . '/.well-known/openid-configuration');
            $json = $resp->json();

            return is_array($json) ? $json : [];
        });
        if (empty($data['authorization_endpoint']) || empty($data['token_endpoint']) || empty($data['jwks_uri'])) {
            throw new SsoException('OIDC-Discovery unvollständig.');
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $disc
     */
    private function jwks(array $disc): JWKSet
    {
        $uri = (string)$disc['jwks_uri'];
        $raw = $this->cache->remember('oidc.jwks.' . md5($uri), function () use ($uri): array {
            $json = $this->egress->get($uri)->json();

            return is_array($json) ? $json : [];
        });

        return JWKSet::createFromKeyData($raw);
    }

    private function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
