<?php
declare(strict_types=1);

namespace App\Test\TestCase\Auth;

use App\Auth\LoginThrottle;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Test des Anmeldeschutzes (Entscheidung 162) — Schwerpunkt Per-IP-Sperre gegen
 * Password-Spraying über viele Benutzernamen (Peer-Review #2).
 */
class LoginThrottleTest extends TestCase
{
    private const IP = '203.0.113.250';

    protected function setUp(): void
    {
        parent::setUp();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    private function purge(): void
    {
        ConnectionManager::get('default')->execute(
            'DELETE FROM auth_failures WHERE ip_address = :ip',
            ['ip' => self::IP],
        );
    }

    public function testIpBlockTriggersOnSprayingAcrossUsernames(): void
    {
        // Per-IP-Schwelle 3, Per-Benutzer 10: drei Fehlversuche von EINER IP über
        // DREI verschiedene Konten sperren die IP, obwohl kein Konto seine eigene
        // Grenze erreicht — genau die Spraying-Lücke, die geschlossen werden soll.
        $t = new LoginThrottle(10, 15, null, 3);
        $this->assertFalse($t->isIpBlocked(self::IP));

        $t->recordFailure('alice', self::IP);
        $t->recordFailure('bob', self::IP);
        $this->assertFalse($t->isIpBlocked(self::IP), '2 < 3 -> noch frei');

        $t->recordFailure('carol', self::IP);
        $this->assertTrue($t->isIpBlocked(self::IP), '3 >= 3 -> IP gesperrt');
        $this->assertSame(3, $t->recentIpFailures(self::IP));

        // Einzelne Konten bleiben unter ihrer eigenen (höheren) Schwelle.
        $this->assertFalse($t->isBlocked('alice'));
    }

    public function testEmptyIpIsNeverBlocked(): void
    {
        $this->assertFalse((new LoginThrottle(10, 15, null, 1))->isIpBlocked(''));
        $this->assertSame(0, (new LoginThrottle(10, 15, null, 1))->recentIpFailures(''));
    }
}
