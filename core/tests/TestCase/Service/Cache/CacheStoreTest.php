<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Cache;

use App\Service\Cache\CacheStore;
use Cake\TestSuite\TestCase;

/**
 * Test des Cache-Helfers (P02): Roundtrip + remember-Memoisierung.
 */
class CacheStoreTest extends TestCase
{
    public function testRoundtrip(): void
    {
        $c = new CacheStore('_app_');
        $c->delete('t.key');
        $this->assertNull($c->get('t.key'));

        $c->set('t.key', ['x' => 1]);
        $this->assertSame(['x' => 1], $c->get('t.key'));

        $c->delete('t.key');
        $this->assertNull($c->get('t.key'));
    }

    public function testIncrementDecrement(): void
    {
        $c = new CacheStore('_app_');
        $key = 'cnt.' . bin2hex(random_bytes(4));
        $this->assertSame(1, $c->increment($key));
        $this->assertSame(2, $c->increment($key));
        $this->assertSame(1, $c->decrement($key));
        $this->assertSame(0, $c->decrement($key));
        $this->assertSame(0, $c->decrement($key), 'Boden bei 0');
        $c->delete($key);
    }

    public function testRememberComputesOnce(): void
    {
        $c = new CacheStore('_app_');
        $c->delete('t.remember');
        $calls = 0;
        $compute = function () use (&$calls): string {
            $calls++;

            return 'value';
        };

        $this->assertSame('value', $c->remember('t.remember', $compute));
        $this->assertSame('value', $c->remember('t.remember', $compute));
        $this->assertSame(1, $calls, 'remember darf nur beim Miss berechnen.');

        $c->delete('t.remember');
    }
}
