<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Http;

use App\Service\Http\EgressClient;
use App\Service\Http\EgressException;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;

/**
 * Test des gehärteten Outbound-HTTP-Primitivs (P01): SSRF-Policy (Schema,
 * privat/reserviert, Allowlist, Override) und Antwort-Mapping/-Begrenzung. Die
 * Netzwerk-Schicht wird gestubbt (kein realer Verkehr).
 */
class EgressClientTest extends TestCase
{
    public function testRejectsNonHttpScheme(): void
    {
        $c = new EgressClient(null, ['allow_private' => false]);
        $this->assertFalse($c->isUrlAllowed('ftp://example.com/x'));
        $this->assertFalse($c->isUrlAllowed('file:///etc/passwd'));
        $this->assertTrue($c->isUrlAllowed('https://93.184.216.34/')); // öffentliche IP-Literal
    }

    public function testBlocksLoopbackAndPrivateAndMetadata(): void
    {
        $c = new EgressClient(null, ['allow_private' => false, 'allowlist' => []]);
        foreach ([
            'http://127.0.0.1/',
            'http://[::1]/',
            'http://10.0.0.1/',
            'http://172.16.5.5/',
            'http://192.168.1.10/',
            'http://169.254.169.254/latest/meta-data/', // Cloud-Metadaten (SSRF-Klassiker)
            'http://0.0.0.0/',
        ] as $url) {
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
