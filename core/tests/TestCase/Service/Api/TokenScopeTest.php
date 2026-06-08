<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Api;

use App\Service\Api\TokenService;
use Cake\TestSuite\TestCase;

class TokenScopeTest extends TestCase
{
    public function testWildcardGrantsEverything(): void
    {
        $this->assertTrue(TokenService::hasScope(['*'], 'me:read'));
        $this->assertTrue(TokenService::hasScope(['*'], 'anything'));
    }

    public function testExactScopeMatches(): void
    {
        $this->assertTrue(TokenService::hasScope(['me:read', 'modules:read'], 'modules:read'));
    }

    public function testMissingScopeDenied(): void
    {
        $this->assertFalse(TokenService::hasScope(['me:read'], 'modules:read'));
    }

    public function testEmptyScopesDenied(): void
    {
        $this->assertFalse(TokenService::hasScope([], 'me:read'));
    }
}
