<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure;

use App\Infrastructure\Uuid;
use Cake\TestSuite\TestCase;

/**
 * Tests the central UUID guard (22P02 protection for raw SQL against uuid
 * columns): accepts exactly the canonical 8-4-4-4-12 format (case-insensitive).
 */
class UuidTest extends TestCase
{
    public function testAcceptsWellFormedUuids(): void
    {
        $this->assertTrue(Uuid::isValid('00000000-0000-0000-0000-000000000000'));
        $this->assertTrue(Uuid::isValid('0197a3c2-1111-7abc-9def-0123456789ab'));
        $this->assertTrue(Uuid::isValid('0197A3C2-1111-7ABC-9DEF-0123456789AB')); // uppercase ok
    }

    public function testRejectsMalformedValues(): void
    {
        $this->assertFalse(Uuid::isValid(''));
        $this->assertFalse(Uuid::isValid('garbage'));
        $this->assertFalse(Uuid::isValid('0197a3c2-1111-7abc-9def-0123456789')); // too short
        $this->assertFalse(Uuid::isValid('0197a3c2-1111-7abc-9def-0123456789ab ')); // whitespace
        $this->assertFalse(Uuid::isValid('0197a3c211117abc9def0123456789ab')); // without hyphens
        $this->assertFalse(Uuid::isValid("0197a3c2-1111-7abc-9def-0123456789ab\n")); // \z anchor applies
    }
}
