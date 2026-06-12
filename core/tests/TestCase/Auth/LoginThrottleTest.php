<?php
declare(strict_types=1);

namespace App\Test\TestCase\Auth;

use App\Auth\LoginThrottle;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Tests the login protection (Decision 162) — focus on the per-IP block against
 * password spraying across many usernames (Peer-Review #2).
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
        // Per-IP threshold 3, per-user 10: three failed attempts from ONE IP across
        // THREE different accounts block the IP, even though no account reaches its
        // own limit — exactly the spraying gap that this is meant to close.
        $t = new LoginThrottle(10, 15, null, 3);
        $this->assertFalse($t->isIpBlocked(self::IP));

        $t->recordFailure('alice', self::IP);
        $t->recordFailure('bob', self::IP);
        $this->assertFalse($t->isIpBlocked(self::IP), '2 < 3 -> noch frei');

        $t->recordFailure('carol', self::IP);
        $this->assertTrue($t->isIpBlocked(self::IP), '3 >= 3 -> IP gesperrt');
        $this->assertSame(3, $t->recentIpFailures(self::IP));

        // Individual accounts stay below their own (higher) threshold.
        $this->assertFalse($t->isBlocked('alice'));
    }

    public function testEmptyIpIsNeverBlocked(): void
    {
        $this->assertFalse((new LoginThrottle(10, 15, null, 1))->isIpBlocked(''));
        $this->assertSame(0, (new LoginThrottle(10, 15, null, 1))->recentIpFailures(''));
    }
}
