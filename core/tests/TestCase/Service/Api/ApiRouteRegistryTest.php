<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Api;

use App\Service\Api\ApiRouteRegistry;
use Cake\TestSuite\TestCase;

/**
 * Tests path matching for module API routes (P07).
 */
class ApiRouteRegistryTest extends TestCase
{
    public function testStaticPathMatches(): void
    {
        $this->assertSame([], ApiRouteRegistry::matchPath('/things', '/things'));
        $this->assertNull(ApiRouteRegistry::matchPath('/things', '/things/1'));
    }

    public function testParameterExtraction(): void
    {
        $params = ApiRouteRegistry::matchPath('/things/{id}', '/things/42');
        $this->assertSame(['id' => '42'], $params);

        $multi = ApiRouteRegistry::matchPath('/orgs/{org}/items/{item}', '/orgs/acme/items/7');
        $this->assertSame(['org' => 'acme', 'item' => '7'], $multi);
    }

    public function testNonMatchingReturnsNull(): void
    {
        $this->assertNull(ApiRouteRegistry::matchPath('/things/{id}', '/things/1/extra'));
        $this->assertNull(ApiRouteRegistry::matchPath('/a/{x}', '/b/1'));
    }

    public function testDuplicatePlaceholderNameIsRejectedCleanly(): void
    {
        // Duplicate placeholder name -> invalid PCRE; must be handled cleanly as
        // a non-match (null), without a fatal/warning per request.
        $this->assertNull(ApiRouteRegistry::matchPath('/a/{id}/b/{id}', '/a/1/b/2'));
        // Unique names still work.
        $this->assertSame(['id' => '1', 'sub' => '2'], ApiRouteRegistry::matchPath('/a/{id}/b/{sub}', '/a/1/b/2'));
    }
}
