<?php
declare(strict_types=1);

namespace App\Service\Auth\Sso;

use OneLogin\Saml2\Auth as SamlAuth;
use Throwable;

/**
 * SAML-2.0-Provider (Programm Tier-1, P06) auf Basis von onelogin/php-saml.
 *
 * SP-initiierter Redirect-Login + Assertion-Consumer-Service (ACS): die
 * Echtheit der Antwort garantiert die **signierte SAML-Assertion** (Prüfung
 * gegen das IdP-Zertifikat). Identitäten/Autorisierung bleiben Core-verwaltet.
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
            ],
            'idp' => [
                'entityId' => (string)($c['idp_entity_id'] ?? ''),
                'singleSignOnService' => [
                    'url' => (string)($c['idp_sso_url'] ?? ''),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => (string)($c['idp_x509cert'] ?? ''),
            ],
        ];
    }

    /**
     * Baut die Redirect-URL zum IdP (SP-initiierter Login). RelayState trägt die
     * Provider-ID zurück zum ACS.
     *
     * @param array<string,mixed> $provider
     */
    public function loginUrl(array $provider, string $acsUrl, string $spEntityId, string $relayState): string
    {
        $auth = new SamlAuth($this->settings($provider, $acsUrl, $spEntityId));

        // stay=true -> URL zurückgeben statt direkt zu redirecten.
        return $auth->login($relayState, [], false, false, true);
    }

    /**
     * Verarbeitet die ACS-Antwort (liest `SAMLResponse` aus den POST-Daten) und
     * liefert die Identitätsdaten.
     *
     * @param array<string,mixed> $provider
     * @return array{sub:string,email:string,first:?string,last:?string}
     */
    public function processAcs(array $provider, string $acsUrl, string $spEntityId): array
    {
        $auth = new SamlAuth($this->settings($provider, $acsUrl, $spEntityId));
        try {
            $auth->processResponse();
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
