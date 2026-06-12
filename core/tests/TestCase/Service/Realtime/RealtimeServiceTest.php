<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Realtime;

use App\Service\Realtime\RealtimeService;
use Cake\TestSuite\TestCase;

/**
 * Test of the realtime service (P08): identifier-safe channel names + publish.
 */
class RealtimeServiceTest extends TestCase
{
    public function testChannelIsStableAndIdentifierSafe(): void
    {
        $ch = RealtimeService::channel('0192abcd-1234-7eef-9abc-def012345678');
        $this->assertMatchesRegularExpression('/^rt_[a-z0-9]+$/', $ch);
        $this->assertSame($ch, RealtimeService::channel('0192abcd-1234-7eef-9abc-def012345678'));
        $this->assertNotSame($ch, RealtimeService::channel('ffffffff-1234-7eef-9abc-def012345678'));
    }

    public function testPublishDoesNotThrow(): void
    {
        // pg_notify without a LISTEN receiver is a no-op (no error).
        (new RealtimeService())->publish('user-1', 'test.ping', ['x' => 1]);
        $this->assertTrue(true);
    }
}
