<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Queue;

use App\Service\Queue\RedisStreamTransport;
use Cake\TestSuite\TestCase;
use Predis\Client;
use Throwable;
use function Cake\Core\env;

/**
 * Test des Redis-Streams-Treibers (#10): push/reserve/ack über eine Consumer-Group.
 * Überspringt sauber, wenn kein Redis erreichbar ist (lokal ohne `redis`-Dienst).
 */
class RedisStreamTransportTest extends TestCase
{
    private Client $client;
    private string $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $url = (string)(env('QUEUE_REDIS_URL') ?: 'tcp://redis:6379');
        try {
            $this->client = new Client($url);
            $this->client->ping();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis nicht erreichbar (' . $url . '): ' . $e->getMessage());
        }
        $this->queue = 'zztest-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            try {
                $this->client->executeRaw(['DEL', 'fertura:q:' . $this->queue]);
            } catch (Throwable) {
            }
        }
        parent::tearDown();
    }

    public function testPushReserveAck(): void
    {
        $t = new RedisStreamTransport($this->client);
        $this->assertSame('redis', $t->name());

        $id = $t->push($this->queue, ['n' => 42]);
        $this->assertNotSame('', $id);

        $res = $t->reserve($this->queue, 5);
        $this->assertCount(1, $res);
        $this->assertSame(42, $res[0]['payload']['n']);

        $t->ack($this->queue, $res[0]['id']);
        // Nach ack liefert reserve keine neuen Einträge mehr.
        $this->assertCount(0, $t->reserve($this->queue, 5));
    }

    public function testMultipleAndOrder(): void
    {
        $t = new RedisStreamTransport($this->client);
        $t->push($this->queue, ['k' => 'a']);
        $t->push($this->queue, ['k' => 'b']);
        $res = $t->reserve($this->queue, 10);
        $keys = array_map(static fn ($r) => $r['payload']['k'], $res);
        $this->assertSame(['a', 'b'], $keys, 'FIFO-Reihenfolge der Streams');
        foreach ($res as $r) {
            $t->ack($this->queue, $r['id']);
        }
    }
}
