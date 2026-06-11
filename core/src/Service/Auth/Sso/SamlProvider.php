<?php
declare(strict_types=1);

namespace App\Service\Auth\Sso;

use OneLogin\Saml2\Auth as SamlAuth;
use Throwable;

/**
 * SAML 2.0 provider (program tier-1, P06) based on onelogin/php-saml.
 *
 * SP-initiated redirect login + assertion consumer service (ACS): the response's
 * authenticity is guaranteed by the **signed SAML assertion** (verified against
 * the IdP certificate). Identities/authorization stay core-managed.
 */
class SamlProvider
{
    /**
     * @param array<string,mixed> $provider
     * @return array<string,mixed>
     */
    public function settings(array $provider, string $acsUrl, string $spEntityId): array
    {
        $c = (array)($provider['config'] ?? []);
        // SP certificate (public, in the config) + SP private key (secret ->
        // provider['secret'], stored AES-encrypted). If both are present,
        // AuthnRequests are **signed** (hardening).
        $spCert = (string)($c['sp_cert'] ?? '');
        $spKey = (string)($provider['secret'] ?? '');
        $signed = $spCert !== '' && $spKey !== '';

        return [
            'strict' => true,
            'debug' => false,
            'sp' => [
                'entityId' => $spEntityId,
                'assertionConsumerService' => [
                    'url' => $acsUrl,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
                'x509cert' => $spCert,
                'privateKey' => $spKey,
            ],
            'idp' => [
                'entityId' => (string)($c['idp_entity_id'] ?? ''),
                'singleSignOnService' => [
                    'url' => (string)($c['idp_sso_url'] ?? ''),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => (string)($c['idp_x509cert'] ?? ''),
            ],
            'security' => [
                'authnRequestsSigned' => $signed,
                'logoutRequestSigned' => $signed,
                'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'wantAssertionsSigned' => true,
                // Replay/hardening: reject unsolicited (IdP-initiated) responses
                // that carry an `InResponseTo` — and accept only SP-initiated
                // responses whose `InResponseTo` matches the AuthnRequest ID we
                // remembered in the session (see processAcs()).
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
            ],
        ];
    }

    /**
     * Builds the redirect URL to the IdP (SP-initiated login). RelayState carries
     * the provider ID back to the ACS. Also returns the **AuthnRequest ID**, which
     * the caller remembers in the session and passes back at the ACS — binding the
     * IdP response to exactly this request (replay protection, single-use).
     *
     * @param array<string,mixed> $provider
     * @return array{url:string, id:?string}
     */
    public function loginRequest(array $provider, string $acsUrl, string $spEntityId, string $relayState): array
    {
        $auth = new SamlAuth($this->settings($provider, $acsUrl, $spEntityId));

        // stay=true -> return the URL instead of redirecting directly.
        $url = $auth->login($relayState, [], false, false, true);

        return ['url' => $url, 'id' => $auth->getLastRequestID()];
    }

    /**
     * Backward-compatible helper: just the redirect URL (without request-ID binding).
     *
     * @param array<string,mixed> $provider
     */
    public function loginUrl(array $provider, string $acsUrl, string $spEntityId, string $relayState): string
    {
        return $this->loginRequest($provider, $acsUrl, $spEntityId, $relayState)['url'];
    }

    /**
     * Processes the ACS response (reads `SAMLResponse` from the POST data) and
     * returns the identity data.
     *
     * `$expectedRequestId` (the AuthnRequest ID previously remembered in the
     * session) binds the response to our request: onelogin checks `InResponseTo`
     * against it and rejects foreign/replayed responses. The caller removes the
     * ID from the session after the ACS, so a response is valid only **once**.
     *
     * @param array<string,mixed> $provider
     * @return array{sub:string,email:string,first:?string,last:?string}
     */
    public function processAcs(array $provider, string $acsUrl, string $spEntityId, ?string $expectedRequestId = null): array
    {
        $auth = new SamlAuth($this->settings($provider, $acsUrl, $spEntityId));
        try {
            $auth->processResponse($expectedRequestId);
        } catch (Throwable $e) {
            throw new SsoException('SAML-Antwort ungültig: ' . $e->getMessage());
        }
        $errors = $auth->getErrors();
        if ($errors !== []) {
            throw new SsoException('SAML-Fehler: ' . implode(', ', $errors) . ' — ' . $auth->getLastErrorReason());
        }
        if (!$auth->isAuthenticated()) {
            throw new SsoException('SAML nicht authentifiziert.');
        }

        $attrs = $auth->getAttributes();
        $nameId = (string)$auth->getNameId();
        $email = $this->firstAttr($attrs, [
            'email', 'mail',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
        ]) ?? ($this->looksLikeEmail($nameId) ? $nameId : '');

        return [
            'sub' => $nameId,
            'email' => (string)$email,
            // SAML attributes are not marked as "verified" -> unknown (null);
            // the linking logic rejects local password accounts anyway.
            'email_verified' => null,
            'first' => $this->firstAttr($attrs, [
                'givenName', 'first_name',
                'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname',
            ]),
            'last' => $this->firstAttr($attrs, [
                'surname', 'sn', 'last_name',
                'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname',
            ]),
        ];
    }

    /**
     * @param array<string, list<string>> $attrs
     * @param list<string> $keys
     */
    public function firstAttr(array $attrs, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (!empty($attrs[$k][0])) {
                return (string)$attrs[$k][0];
            }
        }

        return null;
    }

    private function looksLikeEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
}
