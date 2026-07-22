<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Middleware\UniqueViolationMiddleware;
use Cake\Database\Exception\QueryException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\I18n\I18n;
use Cake\TestSuite\TestCase;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

/**
 * Unit test for the global web safety net: a bubbling 23505 becomes a Flash
 * warning + 303 redirect (browser) or a 409 (JSON); anything else is re-thrown.
 */
class UniqueViolationMiddlewareTest extends TestCase
{
    private string $locale = 'en_US';

    protected function setUp(): void
    {
        parent::setUp();
        $this->locale = I18n::getLocale();
        I18n::setLocale('de_DE');
    }

    protected function tearDown(): void
    {
        I18n::setLocale($this->locale);
        parent::tearDown();
    }

    /** A handler that throws the given throwable. */
    private function throwing(Throwable $e): RequestHandlerInterface
    {
        return new class ($e) implements RequestHandlerInterface {
            public function __construct(private Throwable $e)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->e;
            }
        };
    }

    private function violation(string $constraint = 'uq_groups_tenant_name_lower'): QueryException
    {
        $msg = 'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "'
            . $constraint . '"';
        $pdo = new PDOException($msg);
        $pdo->errorInfo = ['23505', 7, $msg];

        return new QueryException('INSERT ...', $pdo);
    }

    private function request(Session $session, array $env = []): ServerRequest
    {
        return new ServerRequest([
            'environment' => ['HTTP_HOST' => 'localhost', 'REQUEST_METHOD' => 'POST'] + $env,
            'session' => $session,
        ]);
    }

    public function testBrowserGetsFlashWarningAndSameOriginRedirect(): void
    {
        $session = new Session();
        $request = $this->request($session, ['HTTP_REFERER' => 'http://localhost/admin/groups?create']);
        $response = (new UniqueViolationMiddleware())->process($request, $this->throwing($this->violation()));

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('http://localhost/admin/groups?create', $response->getHeaderLine('Location'));
        $flash = (array)$session->read('Flash.flash');
        $this->assertNotEmpty($flash);
        $this->assertSame('flash/warning', $flash[0]['element']);
        $this->assertSame('Eine Gruppe mit diesem Namen existiert bereits.', $flash[0]['message']);
    }

    public function testForeignRefererFallsBackToRoot(): void
    {
        // Open-redirect guard: a cross-origin Referer must not be honoured.
        $session = new Session();
        $request = $this->request($session, ['HTTP_REFERER' => 'http://evil.example/x']);
        $response = (new UniqueViolationMiddleware())->process($request, $this->throwing($this->violation()));

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/', $response->getHeaderLine('Location'));
    }

    public function testJsonClientGets409WithMessage(): void
    {
        $session = new Session();
        $request = $this->request($session, ['HTTP_ACCEPT' => 'application/json']);
        $response = (new UniqueViolationMiddleware())->process($request, $this->throwing($this->violation('uq_unknown')));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $body = json_decode((string)$response->getBody(), true);
        $this->assertSame('Dieser Wert existiert bereits. Bitte einen anderen wählen.', $body['error']);
    }

    public function testNonUniqueErrorIsRethrown(): void
    {
        $session = new Session();
        $request = $this->request($session);
        $this->expectException(RuntimeException::class);
        (new UniqueViolationMiddleware())->process($request, $this->throwing(new RuntimeException('other')));
    }

    public function testSuccessfulRequestPassesThrough(): void
    {
        $session = new Session();
        $request = $this->request($session);
        $ok = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(['status' => 200]);
            }
        };
        $response = (new UniqueViolationMiddleware())->process($request, $ok);
        $this->assertSame(200, $response->getStatusCode());
    }
}
