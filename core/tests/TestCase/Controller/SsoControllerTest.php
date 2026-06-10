<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Integrationstest der SSO-Login-Flows (P06).
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
        // 36-stelliger Wert: passt zwar auf das Routen-Pattern, ist aber kein
        // gültiges UUID. Früher löste das in der uuid-Spalte eine Postgres-
        // QueryException (22P02) aus -> unauthentifizierter 500 inkl. SQL/Stack
        // im Log. Erwartet: wie unbekannter Provider -> 302 auf /login.
        $this->get('/sso/start/' . str_repeat('0', 36));
        $this->assertResponseCode(302);
        $this->assertRedirect('/login');
    }
}
