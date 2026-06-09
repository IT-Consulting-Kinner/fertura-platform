<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Middleware\ApiRateLimitMiddleware;
use App\Service\Settings\SettingsManager;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Test des API-Rate-Limitings (P07): nach Überschreiten 429 + Header.
 */
class ApiRateLimitMiddlewareTest extends TestCase
{
    private mixed $prevLimit;

    protected function setUp(): void
    {
        parent::setUp();
        $sm = new SettingsManager();
        $this->prevLimit = $sm->get('core', 'api.rate_limit.per_minute', 120);
        $sm->set('core', 'api.rate_limit.per_minute', 2);
    }

    protected function tearDown(): void
    {
        (new SettingsManager())->set('core', 'api.rate_limit.per_minute', $this->prevLimit);
        parent::tearDown();
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(['status' => 200]);
            }
        };
    }

    private function request(string $token): ServerRequest
    {
        return (new ServerRequest(['url' => '/api/v1/health']))->withAttribute('apiTokenId', $token);
    }

    public function testLimitsAfterThreshold(): void
    {
        $mw = new ApiRateLimitMiddleware();
        $token = 'tok-' . bin2hex(random_bytes(6));

        $r1 = $mw->process($this->request($token), $this->handler());
        $r2 = $mw->process($this->request($token), $this->handler());
        $r3 = $mw->process($this->request($token), $this->handler());

        $this->assertSame(200, $r1->getStatusCode());
        $this->assertSame('2', $r1->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame('1', $r1->getHeaderLine('X-RateLimit-Remaining'));
        $this->assertSame(200, $r2->getStatusCode());
        $this->assertSame('0', $r2->getHeaderLine('X-RateLimit-Remaining'));

        $this->assertSame(429, $r3->getStatusCode(), 'drittes Mal über dem Limit -> 429');
        $this->assertNotSame('', $r3->getHeaderLine('Retry-After'));
    }

    public function testNonApiPathIsIgnored(): void
    {
        $mw = new ApiRateLimitMiddleware();
        $resp = $mw->process(new ServerRequest(['url' => '/admin']), $this->handler());
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('', $resp->getHeaderLine('X-RateLimit-Limit'));
    }
}
