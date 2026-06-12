<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integration test of the SSO login flows (P06).
 */
class SsoControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableRetainFlashMessages();
    }

    public function testStartWithMalformedProviderIdRedirectsInsteadOf500(): void
    {
        // A 36-character value: it matches the route pattern but is not a valid
        // UUID. Previously this triggered a Postgres QueryException (22P02) on the
        // uuid column -> an unauthenticated 500 incl. SQL/stack in the log.
        // Expected: like an unknown provider -> 302 to /login.
        $this->get('/sso/start/' . str_repeat('0', 36));
        $this->assertResponseCode(302);
        $this->assertRedirect('/login');
    }
}
