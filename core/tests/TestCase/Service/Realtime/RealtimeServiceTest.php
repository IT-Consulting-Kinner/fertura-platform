<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Realtime;

use App\Service\Realtime\RealtimeService;
use Cake\TestSuite\TestCase;

/**
 * Test des Realtime-Services (P08): identifier-sichere Kanalnamen + Publish.
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
        // pg_notify ohne LISTEN-Empfänger ist ein No-op (kein Fehler).
        (new RealtimeService())->publish('user-1', 'test.ping', ['x' => 1]);
        $this->assertTrue(true);
    }
}
