<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Http;

use App\Service\Http\EgressClient;
use App\Service\Http\EgressException;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;

/**
 * Tests the hardened outbound HTTP primitive (P01): SSRF policy (scheme,
 * private/reserved, allowlist, override) and response mapping/capping. The
 * network layer is stubbed (no real traffic).
 */
class EgressClientTest extends TestCase
{
    public function testRejectsNonHttpScheme(): void
    {
        $c = new EgressClient(null, ['allow_private' => false]);
        $this->assertFalse($c->isUrlAllowed('ftp://example.com/x'));
        $this->assertFalse($c->isUrlAllowed('file:///etc/passwd'));
        $this->assertTrue($c->isUrlAllowed('https://93.184.216.34/')); // public IP literal
    }

    public function testBlocksLoopbackAndPrivateAndMetadata(): void
    {
        $c = new EgressClient(null, ['allow_private' => false, 'allowlist' => []]);
        foreach (
            [
            'http://127.0.0.1/',
            'http://[::1]/',
            'http://10.0.0.1/',
            'http://172.16.5.5/',
            'http://192.168.1.10/',
            'http://169.254.169.254/latest/meta-data/', // cloud metadata (classic SSRF)
            'http://0.0.0.0/',
            ] as $url
        ) {
            $this->assertFalse($c->isUrlAllowed($url), "muss blockiert sein: $url");
        }
    }

    public function testAllowlistBypassesPrivateBlock(): void
    {
        $c = new EgressClient(null, ['allow_private' => false, 'allowlist' => ['10.0.0.5']]);
        $this->assertTrue($c->isUrlAllowed('http://10.0.0.5/internal'));
        $this->assertFalse($c->isUrlAllowed('http://10.0.0.6/internal'), 'nur die Allowlist-IP ist frei');
    }

    public function testAllowPrivateOverride(): void
    {
        $c = new EgressClient(null, ['allow_private' => true]);
        $this->assertTrue($c->isUrlAllowed('http://127.0.0.1/'));
    }

    public function testPinTargetForHostnameReturnsValidatedIp(): void
    {
        // Host with a fixed (public) resolution -> CURLOPT_RESOLVE pin.
        $c = $this->pinClient(['93.184.216.34']);
        $this->assertSame('example.test:443:93.184.216.34', $c->pinTarget('https://example.test/x'));
        $this->assertSame('example.test:80:93.184.216.34', $c->pinTarget('http://example.test/'));
    }

    public function testPinTargetRejectsPrivateResolution(): void
    {
        $c = $this->pinClient(['10.0.0.5']); // rebinding attempt -> private
        $this->expectException(EgressException::class);
        $c->pinTarget('https://example.test/x');
    }

    public function testPinTargetPinsBothFamiliesIncludingIpv6(): void
    {
        // Dual-stack: both validated addresses are pinned (IPv6 in brackets),
        // so curl cannot fall back to an unchecked address family.
        $c = $this->pinClient(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']);
        $this->assertSame(
            'example.test:443:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]',
            $c->pinTarget('https://example.test/x'),
        );
    }

    public function testPinTargetRejectsPrivateIpv6InDualStack(): void
    {
        // A private AAAA record alongside an otherwise public A must block.
        $c = $this->pinClient(['93.184.216.34', 'fd00::1']);
        $this->expectException(EgressException::class);
        $c->pinTarget('https://example.test/x');
    }

    public function testPinTargetNullForLiteralAndOverrides(): void
    {
        $this->assertNull((new EgressClient(null, ['allow_private' => false]))->pinTarget('https://93.184.216.34/'));
        $this->assertNull((new EgressClient(null, ['allow_private' => true]))->pinTarget('https://example.test/'));
        $this->assertNull((new EgressClient(null, ['allowlist' => ['example.test']]))->pinTarget('https://example.test/'));
    }

    /** @param list<string> $ips */
    private function pinClient(array $ips): EgressClient
    {
        return new class ($ips) extends EgressClient {
            public function __construct(private array $stubIps)
            {
                parent::__construct(null, ['allow_private' => false, 'allowlist' => []]);
            }

            protected function resolveHostIps(string $host): array
            {
                return $this->stubIps;
            }
        };
    }

    public function testAssertThrowsWithReason(): void
    {
        $this->expectException(EgressException::class);
        $this->expectExceptionMessageMatches('/SSRF|privat|reserviert/');
        (new EgressClient(null, ['allow_private' => false]))->assertUrlAllowed('http://169.254.169.254/');
    }

    public function testSendReturnsMappedResponse(): void
    {
        $canned = new Response(['HTTP/1.1 201 Created', 'Content-Type: application/json'], '{"ok":true}');
        $c = $this->stub($canned, ['allow_private' => true]);

        $resp = $c->postJson('http://127.0.0.1/hook', ['a' => 1]);
        $this->assertSame(201, $resp->statusCode);
        $this->assertTrue($resp->isSuccess());
        $this->assertSame(['ok' => true], $resp->json());
    }

    public function testResponseBodyIsCapped(): void
    {
        $canned = new Response(['HTTP/1.1 200 OK'], str_repeat('A', 50));
        $c = $this->stub($canned, ['allow_private' => true, 'max_response_bytes' => 10]);

        $resp = $c->get('http://127.0.0.1/big');
        $this->assertSame(10, strlen($resp->body), 'Body muss auf das Limit gekürzt werden.');
    }

    public function testRejectsByContentLength(): void
    {
        $canned = new Response(['HTTP/1.1 200 OK', 'Content-Length: 999999'], 'x');
        $c = $this->stub($canned, ['allow_private' => true, 'max_response_bytes' => 10]);

        $this->expectException(EgressException::class);
        $this->expectExceptionMessageMatches('/zu groß/');
        $c->get('http://127.0.0.1/big');
    }

    public function testTimeoutConfigOverrideAppliedToRequest(): void
    {
        // A passed timeout_seconds config wins over settings/defaults and reaches
        // the request opts — this is the mechanism AiGateway uses to give LLM calls
        // their own (higher) timeout without dilating the shared egress timeout.
        $c = new class (new Response(['HTTP/1.1 200 OK'], 'ok')) extends EgressClient {
            /**
             * @var array<string,mixed>
             */
            public array $lastOpts = [];

            public function __construct(private Response $canned)
            {
                parent::__construct(null, ['allow_private' => true, 'timeout_seconds' => 123]);
            }

            protected function sendRequest(string $method, string $url, mixed $data, array $opts): Response
            {
                $this->lastOpts = $opts;

                return $this->canned;
            }
        };

        $c->get('http://127.0.0.1/x');
        $this->assertSame(123, $c->lastOpts['timeout']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function stub(Response $canned, array $config): EgressClient
    {
        return new class ($canned, $config) extends EgressClient {
            public function __construct(private Response $canned, array $config)
            {
                parent::__construct(null, $config);
            }

            protected function sendRequest(string $method, string $url, mixed $data, array $opts): Response
            {
                return $this->canned;
            }
        };
    }
}
