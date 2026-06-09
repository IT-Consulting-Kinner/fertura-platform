<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Module;

use App\Service\Module\RpcCapabilityToken;
use Cake\TestSuite\TestCase;

/**
 * Unit-Test des Pro-Aufruf-Capability-Tokens (Kap. 23.16.2): MAC-Roundtrip,
 * Aufruf-Bindung (Tamper-Schutz), Geheimnis-Bindung, Ablauf und stabile
 * Kanonisierung. Reine Logik, keine DB/Sockets.
 */
class RpcCapabilityTokenTest extends TestCase
{
    private const SECRET = 'geheimer-hmac-schluessel';

    /** @return array<string, mixed> */
    private function sampleRequest(): array
    {
        return [
            'op' => 'call',
            'class' => 'Acme\\Service\\Foo',
            'method' => 'handle',
            'args' => [['msg' => 'hallo'], 7],
            'rls' => ['user_id' => 'u1', 'group_ids' => ['g1', 'g2'], 'bypass' => false],
        ];
    }

    public function testMintAndVerifyRoundtrip(): void
    {
        $req = $this->sampleRequest();
        $full = $req + RpcCapabilityToken::mint(self::SECRET, $req);

        $this->assertArrayHasKey('cap', $full);
        $this->assertArrayHasKey('nonce', $full);
        $this->assertArrayHasKey('exp', $full);
        $this->assertTrue(RpcCapabilityToken::verify(self::SECRET, $full));
    }

    public function testTamperedRequestIsRejected(): void
    {
        $req = $this->sampleRequest();
        $full = $req + RpcCapabilityToken::mint(self::SECRET, $req);

        // Methode umbiegen -> MAC passt nicht mehr.
        $m = $full;
        $m['method'] = 'evil';
        $this->assertFalse(RpcCapabilityToken::verify(self::SECRET, $m), 'Geänderte Methode muss auffallen.');

        // RLS-Eskalation (bypass=true) -> MAC passt nicht mehr.
        $b = $full;
        $b['rls'] = ['user_id' => 'u1', 'group_ids' => ['g1', 'g2'], 'bypass' => true];
        $this->assertFalse(RpcCapabilityToken::verify(self::SECRET, $b), 'RLS-Bypass-Eskalation muss auffallen.');

        // Argumente ändern -> MAC passt nicht mehr.
        $a = $full;
        $a['args'] = [['msg' => 'manipuliert'], 7];
        $this->assertFalse(RpcCapabilityToken::verify(self::SECRET, $a), 'Geänderte Argumente müssen auffallen.');
    }

    public function testWrongSecretIsRejected(): void
    {
        $req = $this->sampleRequest();
        $full = $req + RpcCapabilityToken::mint(self::SECRET, $req);

        $this->assertFalse(RpcCapabilityToken::verify('falsches-geheimnis', $full));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $req = $this->sampleRequest();
        $full = $req + RpcCapabilityToken::mint(self::SECRET, $req, 1_000);

        $this->assertTrue(RpcCapabilityToken::verify(self::SECRET, $full, 1_000), 'Frisch muss gültig sein.');
        $this->assertFalse(
            RpcCapabilityToken::verify(self::SECRET, $full, 1_000 + RpcCapabilityToken::TTL_SECONDS + 1),
            'Nach Ablauf muss es ungültig sein.',
        );
    }

    public function testImplausibleFutureExpiryIsRejected(): void
    {
        $req = $this->sampleRequest();
        // Token in ferner Zukunft ausgestellt, aber „jetzt" ist viel früher.
        $full = $req + RpcCapabilityToken::mint(self::SECRET, $req, 1_000_000);

        $this->assertFalse(RpcCapabilityToken::verify(self::SECRET, $full, 1_000));
    }

    public function testMissingAuthFieldsAreRejected(): void
    {
        $this->assertFalse(RpcCapabilityToken::verify(self::SECRET, $this->sampleRequest()));
        $this->assertFalse(RpcCapabilityToken::verify(self::SECRET, []));
    }

    public function testCanonicalIsKeyOrderIndependent(): void
    {
        $a = RpcCapabilityToken::canonical(['b' => 1, 'a' => ['y' => 2, 'x' => 1]]);
        $b = RpcCapabilityToken::canonical(['a' => ['x' => 1, 'y' => 2], 'b' => 1]);
        $this->assertSame($a, $b, 'Kanonisierung muss reihenfolge-unabhängig sein.');
    }

    public function testCanonicalIgnoresAuthFields(): void
    {
        $base = ['op' => 'call', 'class' => 'X', 'method' => 'm'];
        $withAuth = $base + ['nonce' => 'n', 'exp' => 123, 'cap' => 'abc', 'token' => 't'];
        $this->assertSame(
            RpcCapabilityToken::canonical($base),
            RpcCapabilityToken::canonical($withAuth),
            'Auth-Felder dürfen die Kanonisierung nicht beeinflussen.',
        );
    }

    public function testCanonicalPreservesListOrder(): void
    {
        // Listen sind reihenfolge-relevant (z. B. positionale Argumente).
        $this->assertNotSame(
            RpcCapabilityToken::canonical(['args' => [1, 2, 3]]),
            RpcCapabilityToken::canonical(['args' => [3, 2, 1]]),
        );
    }
}
