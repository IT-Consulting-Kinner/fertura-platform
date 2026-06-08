<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Security;

use App\Service\Security\TrustStore;
use Cake\TestSuite\TestCase;

class TrustValidityTest extends TestCase
{
    private const NOW = 1700000000; // 2023-11-14

    public function testUnboundedIsValid(): void
    {
        $this->assertTrue(TrustStore::validity([], self::NOW)['ok']);
    }

    public function testExpired(): void
    {
        $r = TrustStore::validity(['valid_to' => '2000-01-01T00:00:00+00:00'], self::NOW);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('abgelaufen', (string)$r['reason']);
    }

    public function testNotYetValid(): void
    {
        $r = TrustStore::validity(['valid_from' => '2999-01-01T00:00:00+00:00'], self::NOW);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('noch nicht', (string)$r['reason']);
    }

    public function testWithinWindow(): void
    {
        $r = TrustStore::validity(
            ['valid_from' => '2000-01-01T00:00:00+00:00', 'valid_to' => '2999-01-01T00:00:00+00:00'],
            self::NOW,
        );
        $this->assertTrue($r['ok']);
        $this->assertNull($r['reason']);
    }
}
