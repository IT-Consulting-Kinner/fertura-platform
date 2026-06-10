<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Auth\Sso;

use App\Service\Auth\Sso\SamlProvider;
use Cake\TestSuite\TestCase;

/**
 * Test der SAML-Glue (P06): Settings-Aufbau aus der Provider-Konfig und
 * Attribut-Auflösung (die signaturbasierte ACS-Prüfung übernimmt onelogin).
 */
class SamlProviderTest extends TestCase
{
    public function testSettingsMapProviderConfig(): void
    {
        $provider = ['config' => [
            'idp_entity_id' => 'https://idp/entity',
            'idp_sso_url' => 'https://idp/sso',
            'idp_x509cert' => 'CERT',
        ]];
        $s = (new SamlProvider())->settings($provider, 'https://sp/acs', 'https://sp/meta');

        $this->assertTrue($s['strict']);
        $this->assertSame('https://sp/meta', $s['sp']['entityId']);
        $this->assertSame('https://sp/acs', $s['sp']['assertionConsumerService']['url']);
        $this->assertSame('https://idp/entity', $s['idp']['entityId']);
        $this->assertSame('https://idp/sso', $s['idp']['singleSignOnService']['url']);
        $this->assertSame('CERT', $s['idp']['x509cert']);
    }

    public function testSpSigningEnabledWhenCertAndKeyPresent(): void
    {
        $provider = [
            'config' => ['idp_entity_id' => 'i', 'idp_sso_url' => 'u', 'idp_x509cert' => 'IC', 'sp_cert' => 'SPCERT'],
            'secret' => 'SPKEY',
        ];
        $s = (new SamlProvider())->settings($provider, 'https://sp/acs', 'https://sp/meta');

        $this->assertSame('SPCERT', $s['sp']['x509cert']);
        $this->assertSame('SPKEY', $s['sp']['privateKey']);
        $this->assertTrue($s['security']['authnRequestsSigned']);
        $this->assertTrue($s['security']['wantAssertionsSigned']);
    }

    public function testSpSigningDisabledWithoutKey(): void
    {
        $s = (new SamlProvider())->settings(['config' => ['idp_entity_id' => 'i']], 'https://sp/acs', 'https://sp/meta');
        $this->assertFalse($s['security']['authnRequestsSigned']);
    }

    public function testRejectsUnsolicitedResponsesWithInResponseTo(): void
    {
        // Replay-Härtung: unaufgeforderte Antworten mit InResponseTo werden
        // abgelehnt; SP-initiierte Antworten bindet processAcs() an die Request-ID.
        $s = (new SamlProvider())->settings(['config' => ['idp_entity_id' => 'i']], 'https://sp/acs', 'https://sp/meta');
        $this->assertTrue($s['security']['rejectUnsolicitedResponsesWithInResponseTo']);
    }

    public function testFirstAttrResolvesByPriority(): void
    {
        $p = new SamlProvider();
        $attrs = [
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress' => ['a@b.de'],
            'givenName' => ['Erika'],
        ];
        $this->assertSame('a@b.de', $p->firstAttr($attrs, ['email', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress']));
        $this->assertSame('Erika', $p->firstAttr($attrs, ['givenName', 'first_name']));
        $this->assertNull($p->firstAttr($attrs, ['nope']));
    }
}
