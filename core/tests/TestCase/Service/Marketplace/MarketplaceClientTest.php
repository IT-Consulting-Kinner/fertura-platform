<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Marketplace;

use App\Service\Marketplace\MarketplaceClient;
use App\Service\Security\Signer;
use App\Service\Security\TrustChain;
use App\Service\Security\TrustStore;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;

/**
 * Tests marketplace communication (ch. 28.4–28.6, Decision 139) against an
 * HTTP stub: only **signature-verified** CRL/anchor documents are applied
 * (tampered ones are discarded), publisher anchors only with a valid root
 * signature (chain), and CRL fetches are timestamped (stale detection, ch. 24.9.2).
 */
class MarketplaceClientTest extends TestCase
{
    private string $rootId = '';
    private string $rootSecret = '';
    private string $rootPublic = '';
    private string $pubId = '';
    private string $victimId = '';
    private mixed $savedCrlFetch = false;

    private const PUBLISHER = 'Fertura Test Verlag';

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = bin2hex(random_bytes(5));
        $this->rootId = 'ztest-mp-root-' . $suffix;
        $this->pubId = 'ztest-mp-pub-' . $suffix;
        $this->victimId = 'ztest-mp-victim-' . $suffix;

        $root = Signer::generateKeypair();
        $this->rootSecret = $root['secret'];
        $this->rootPublic = $root['public'];
        (new TrustStore())->addAnchor($this->rootId, $root['public'], 'root');

        // Preserve the global CRL timestamp (the test mutates it).
        $row = ConnectionManager::get('default')->execute(
            "SELECT meta_value FROM marketplace_meta WHERE meta_key = 'last_crl_fetch_at'",
        )->fetch('assoc');
        $this->savedCrlFetch = $row === false ? false : $row['meta_value'];
    }

    protected function tearDown(): void
    {
        $conn = ConnectionManager::get('default');
        foreach ([$this->rootId, $this->pubId, $this->victimId] as $kid) {
            $conn->execute('DELETE FROM trust_anchors WHERE key_id = :k', ['k' => $kid]);
            $conn->execute('DELETE FROM revoked_keys WHERE key_id = :k', ['k' => $kid]);
        }
        // Restore the CRL timestamp (global state).
        if ($this->savedCrlFetch === false) {
            $conn->execute("DELETE FROM marketplace_meta WHERE meta_key = 'last_crl_fetch_at'");
        } else {
            $conn->execute(
                "INSERT INTO marketplace_meta (meta_key, meta_value, updated_at) VALUES ('last_crl_fetch_at', :v, now()) "
                . 'ON CONFLICT (meta_key) DO UPDATE SET meta_value = EXCLUDED.meta_value',
                ['v' => $this->savedCrlFetch],
            );
        }
        parent::tearDown();
    }

    /** @param array<string,string> $bodiesByPath */
    private function client(array $bodiesByPath): MarketplaceClient
    {
        $http = new class ($bodiesByPath) extends Client {
            /** @param array<string,string> $bodies */
            public function __construct(private array $bodies)
            {
                parent::__construct();
            }

            public function get(string $url, $data = [], array $options = []): Response
            {
                foreach ($this->bodies as $path => $body) {
                    if (str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', $path)) {
                        return new Response(['HTTP/1.1 200 OK'], $body);
                    }
                }

                return new Response(['HTTP/1.1 404 Not Found'], '');
            }
        };
        $settings = new class extends SettingsManager {
            public function get(string $namespace, string $key, mixed $default = null): mixed
            {
                return $key === 'marketplace.base_url' ? 'https://mp.example' : $default;
            }
        };

        return new MarketplaceClient(null, null, $settings, null, $http);
    }

    /** @param array<string,mixed> $payload */
    private function signedDoc(array $payload): string
    {
        return (string)json_encode([
            'payload' => $payload,
            'key_id' => $this->rootId,
            'signature' => (new Signer())->sign(MarketplaceClient::canonical($payload), $this->rootSecret),
        ]);
    }

    public function testSyncAppliesSignedCrlAndChainedAnchor(): void
    {
        $pub = Signer::generateKeypair();
        $statement = TrustChain::keyStatement($this->pubId, $pub['public'], self::PUBLISHER);
        $keySignature = (new Signer())->sign($statement, $this->rootSecret);

        $client = $this->client([
            'crl.json' => $this->signedDoc(['revoked' => [['key_id' => $this->victimId, 'reason' => 'Test']]]),
            'anchors.json' => $this->signedDoc(['anchors' => [[
                'key_id' => $this->pubId, 'public_key' => $pub['public'], 'type' => 'publisher',
                'publisher' => self::PUBLISHER, 'signed_by' => $this->rootId, 'key_signature' => $keySignature,
            ]]]),
        ]);

        $result = $client->sync();

        $this->assertSame(1, $result['revoked']);
        $this->assertSame(1, $result['anchors']);
        $trust = new TrustStore();
        $this->assertTrue($trust->isRevoked($this->victimId));
        $this->assertNotNull($trust->getAnchor($this->pubId));

        // A successful CRL fetch is timestamped -> not stale, age 0 days.
        $state = $client->crlState();
        $this->assertFalse($state['stale']);
        $this->assertSame(0, $state['age_days']);
    }

    public function testSyncRejectsTamperedCrl(): void
    {
        // Signature over a DIFFERENT payload -> document is discarded, nothing applied.
        $doc = json_decode($this->signedDoc(['revoked' => []]), true);
        $doc['payload'] = ['revoked' => [['key_id' => $this->victimId]]];

        $result = $this->client(['crl.json' => (string)json_encode($doc)])->sync();

        $this->assertSame(0, $result['revoked']);
        $this->assertFalse((new TrustStore())->isRevoked($this->victimId));
    }

    public function testSyncRejectsPublisherAnchorWithoutValidRootSignature(): void
    {
        $pub = Signer::generateKeypair();
        // Document signature is valid, but the publisher chain (key_signature) is missing/wrong.
        $client = $this->client([
            'anchors.json' => $this->signedDoc(['anchors' => [[
                'key_id' => $this->pubId, 'public_key' => $pub['public'], 'type' => 'publisher',
                'publisher' => self::PUBLISHER, 'signed_by' => $this->rootId, 'key_signature' => 'invalid',
            ]]]),
        ]);

        $result = $client->sync();

        $this->assertSame(0, $result['anchors']);
        $this->assertNull((new TrustStore())->getAnchor($this->pubId));
    }

    public function testMetadataReturnsVerifiedPayloadOrNull(): void
    {
        $signed = $this->client(['metadata.json' => $this->signedDoc(['packages' => ['a' => '1.0.0']])]);
        $meta = $signed->metadata();
        $this->assertNotNull($meta);
        $this->assertSame(['a' => '1.0.0'], $meta['packages']);

        // Unsigned document -> null (Decision 139: only verified content takes effect).
        $unsigned = $this->client(['metadata.json' => (string)json_encode(['packages' => []])]);
        $this->assertNull($unsigned->metadata());

        // 404/unreachable -> null, no error.
        $this->assertNull($this->client([])->metadata());
    }

    public function testCrlStateDetectsStaleness(): void
    {
        ConnectionManager::get('default')->execute(
            "INSERT INTO marketplace_meta (meta_key, meta_value, updated_at) VALUES ('last_crl_fetch_at', :v, now()) "
            . 'ON CONFLICT (meta_key) DO UPDATE SET meta_value = EXCLUDED.meta_value',
            ['v' => date('c', time() - 30 * 86400)],
        );

        $state = $this->client([])->crlState();

        $this->assertTrue($state['stale']); // 30 days > default threshold 7
        $this->assertSame(30, $state['age_days']);
        $this->assertSame(7, $state['max_age_days']);
    }
}
