<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Middleware\SelectiveMaintenanceMiddleware;
use App\Service\System\AllowTokenCookie;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The selective maintenance gate (Phase 3): blocks everyone with 503 while a session
 * is engaged EXCEPT the operator (by identity OR allow-token cookie), and allow-lists
 * health probes. The maintenance session lives in the real DB and is cleaned around
 * each test so it never leaks into the rest of the suite (it would 503 every request).
 */
class SelectiveMaintenanceMiddlewareTest extends TestCase
{
    private const ACTOR = '11111111-1111-7111-8111-111111111111';
    private string $plainToken = 'allow-token-plain';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        ConnectionManager::get('default')->execute('DELETE FROM core.maintenance_session');
    }

    private function engage(): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO core.maintenance_session (actor_user_id, allow_token_hash) VALUES (:a, :h)',
            ['a' => self::ACTOR, 'h' => hash('sha256', $this->plainToken)],
        );
    }

    /** A handler that marks a pass-through with a 200/"PASSED" response. */
    private function passHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(200)->withStringBody('PASSED');
            }
        };
    }

    private function identity(string $id): object
    {
        return new class ($id) {
            public function __construct(private string $id)
            {
            }

            public function getIdentifier(): string
            {
                return $this->id;
            }
        };
    }

    private function dispatch(ServerRequest $request): ResponseInterface
    {
        return (new SelectiveMaintenanceMiddleware())->process($request, $this->passHandler());
    }

    public function testPassesWhenNotInMaintenance(): void
    {
        $res = $this->dispatch(new ServerRequest(['url' => '/admin/foo']));
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testBlocksAnonymousDuringMaintenance(): void
    {
        $this->engage();
        $res = $this->dispatch(new ServerRequest(['url' => '/admin/foo']));
        $this->assertSame(503, $res->getStatusCode());
    }

    public function testAllowsTheActorIdentity(): void
    {
        $this->engage();
        $request = (new ServerRequest(['url' => '/admin/foo']))->withAttribute('identity', $this->identity(self::ACTOR));
        $this->assertSame(200, $this->dispatch($request)->getStatusCode());
    }

    public function testBlocksADifferentIdentity(): void
    {
        $this->engage();
        $other = (new ServerRequest(['url' => '/admin/foo']))
            ->withAttribute('identity', $this->identity('22222222-2222-7222-8222-222222222222'));
        $this->assertSame(503, $this->dispatch($other)->getStatusCode());
    }

    public function testAllowsTheAllowTokenCookie(): void
    {
        $this->engage();
        $request = (new ServerRequest(['url' => '/admin/foo']))
            ->withCookieParams([AllowTokenCookie::NAME => $this->plainToken]);
        $this->assertSame(200, $this->dispatch($request)->getStatusCode());
    }

    public function testBlocksAWrongCookie(): void
    {
        $this->engage();
        $request = (new ServerRequest(['url' => '/admin/foo']))
            ->withCookieParams([AllowTokenCookie::NAME => 'wrong-token']);
        $this->assertSame(503, $this->dispatch($request)->getStatusCode());
    }

    public function testAllowListsLbProbesOnly(): void
    {
        $this->engage();
        // LB liveness + readiness stay reachable.
        $this->assertSame(200, $this->dispatch(new ServerRequest(['url' => '/health']))->getStatusCode());
        $this->assertSame(200, $this->dispatch(new ServerRequest(['url' => '/health/ready']))->getStatusCode());
        // Internal detail + metrics stay behind the gate (no state leak past lockout).
        $this->assertSame(503, $this->dispatch(new ServerRequest(['url' => '/metrics']))->getStatusCode());
        $this->assertSame(503, $this->dispatch(new ServerRequest(['url' => '/health/detail']))->getStatusCode());
        // A look-alike prefix must NOT slip the exact-matched allow-list.
        $this->assertSame(503, $this->dispatch(new ServerRequest(['url' => '/healthcheck-evil']))->getStatusCode());
    }

    public function testBlocksAuthPathsDuringMaintenance(): void
    {
        // Fail-closed login: anonymous auth attempts during maintenance are 503'd
        // (no operator identity, no allow-token), so nobody can sign in to bypass.
        $this->engage();
        foreach (['/login', '/sso/saml/acs', '/login/mfa'] as $path) {
            $this->assertSame(503, $this->dispatch(new ServerRequest(['url' => $path]))->getStatusCode(), $path);
        }
    }

    public function testApiBlockReturnsJson(): void
    {
        $this->engage();
        $res = $this->dispatch(new ServerRequest(['url' => '/api/v1/things']));
        $this->assertSame(503, $res->getStatusCode());
        $this->assertStringContainsString('application/json', $res->getHeaderLine('Content-Type'));
    }
}
