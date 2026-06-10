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
        // SP-Zertifikat (öffentlich, in der Konfig) + SP-Privatschlüssel
        // (geheim -> provider['secret'], AES-verschlüsselt gespeichert). Sind
        // beide vorhanden, werden AuthnRequests **signiert** (Härtung).
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
                // Replay-/Härtung: unaufgeforderte (IdP-initiierte) Antworten, die
                // ein `InResponseTo` tragen, ablehnen — und nur SP-initiierte
                // Antworten akzeptieren, deren `InResponseTo` zu unserer in der
                // Session gemerkten AuthnRequest-ID passt (siehe processAcs()).
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
            ],
        ];
    }

    /**
     * Baut die Redirect-URL zum IdP (SP-initiierter Login). RelayState trägt die
     * Provider-ID zurück zum ACS. Liefert zusätzlich die **AuthnRequest-ID**, die
     * der Aufrufer in der Session merkt und beim ACS wieder mitgibt — so wird die
     * IdP-Antwort an genau diese Anfrage gebunden (Replay-Schutz, einmalig).
     *
     * @param array<string,mixed> $provider
     * @return array{url:string, id:?string}
     */
    public function loginRequest(array $provider, string $acsUrl, string $spEntityId, string $relayState): array
    {
        $auth = new SamlAuth($this->settings($provider, $acsUrl, $spEntityId));

        // stay=true -> URL zurückgeben statt direkt zu redirecten.
        $url = $auth->login($relayState, [], false, false, true);

        return ['url' => $url, 'id' => $auth->getLastRequestID()];
    }

    /**
     * Rückwärtskompatibler Helfer: nur die Redirect-URL (ohne Request-ID-Bindung).
     *
     * @param array<string,mixed> $provider
     */
    public function loginUrl(array $provider, string $acsUrl, string $spEntityId, string $relayState): string
    {
        return $this->loginRequest($provider, $acsUrl, $spEntityId, $relayState)['url'];
    }

    /**
     * Verarbeitet die ACS-Antwort (liest `SAMLResponse` aus den POST-Daten) und
     * liefert die Identitätsdaten.
     *
     * Der `$expectedRequestId` (zuvor in der Session gemerkte AuthnRequest-ID)
     * bindet die Antwort an unsere Anfrage: onelogin prüft `InResponseTo` dagegen
     * und lehnt fremde/wiederholte Antworten ab. Der Aufrufer löscht die ID nach
     * dem ACS aus der Session, sodass eine Antwort nur **einmal** gültig ist.
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
            // SAML-Attribute sind nicht als „verifiziert" markiert -> unbekannt
            // (null); die Zuordnung lehnt lokale Passwortkonten ohnehin ab.
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
