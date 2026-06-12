<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Webhook;

use App\Service\Http\EgressClient;
use App\Service\Http\EgressResponse;
use App\Service\Webhook\WebhookService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Tests outbound webhooks (P05): HMAC signature, filter matching, idempotent
 * enqueueing, successful delivery (incl. signature header), and retry →
 * dead-letter. The HTTP egress is stubbed (no real traffic).
 */
class WebhookServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->execute("DELETE FROM webhook_subscriptions WHERE name LIKE 'zztest_%'");
        $conn->execute("DELETE FROM webhook_deliveries WHERE event_name LIKE 'zztest.%'");
    }

    public function testSignatureIsStableHmac(): void
    {
        $s = new WebhookService();
        $this->assertSame(
            hash_hmac('sha256', '123.{"a":1}', 'secret'),
            $s->signature('secret', '123', '{"a":1}'),
        );
    }

    public function testEnqueueMatchesFiltersAndIsIdempotent(): void
    {
        $s = new WebhookService(new FakeEgress());
        $all = $s->createSubscription('zztest_all', 'https://example.com/a', '*')['id'];
        $exact = $s->createSubscription('zztest_exact', 'https://example.com/b', 'zztest.hit')['id'];
        $s->createSubscription('zztest_other', 'https://example.com/c', 'zztest.miss');

        $eventId = $this->uuid();
        $n = $s->enqueueForEvent('zztest.hit', ['x' => 1], $eventId);
        $this->assertSame(2, $n, 'nur Wildcard + exakter Treffer');

        // Idempotent: the same event again -> 0 new ones.
        $this->assertSame(0, $s->enqueueForEvent('zztest.hit', ['x' => 1], $eventId));

        $subs = array_column($s->listDeliveries(), 'subscription_id');
        $this->assertContains($all, $subs);
        $this->assertContains($exact, $subs);
    }

    public function testDeliverySucceedsAndSigns(): void
    {
        $egress = new FakeEgress(200);
        $s = new WebhookService($egress);
        $s->createSubscription('zztest_sig', 'https://example.com/hook', 'zztest.sig', 'topsecret');
        $s->enqueueForEvent('zztest.sig', ['hello' => 'world'], $this->uuid());

        $this->assertSame(1, $s->deliverPending());

        $this->assertCount(1, $egress->calls);
        $headers = $egress->calls[0]['options']['headers'];
        $body = $egress->calls[0]['options']['data'];
        $this->assertSame('zztest.sig', $headers['X-Fertura-Event']);
        $expected = 'sha256=' . hash_hmac('sha256', $headers['X-Fertura-Timestamp'] . '.' . $body, 'topsecret');
        $this->assertSame($expected, $headers['X-Fertura-Signature']);

        $done = $s->listDeliveries('done');
        $this->assertCount(1, $done);
        $this->assertSame(200, (int)$done[0]['last_status_code']);
    }

    public function testRetryThenDeadLetter(): void
    {
        $egress = new FakeEgress(500); // permanently failing
        $s = new WebhookService($egress);
        $s->createSubscription('zztest_dead', 'https://example.com/fail', 'zztest.dead', 'k');
        $s->enqueueForEvent('zztest.dead', [], $this->uuid());

        // Set max_attempts to 1 -> the first failed attempt lands in the dead-letter.
        ConnectionManager::get('default')->execute(
            "UPDATE webhook_deliveries SET max_attempts = 1 WHERE event_name = 'zztest.dead'",
        );

        $s->deliverPending();
        $dead = $s->listDeliveries('dead_letter');
        $this->assertCount(1, $dead);
        $this->assertSame(500, (int)$dead[0]['last_status_code']);
    }

    private function uuid(): string
    {
        return ConnectionManager::get('default')
            ->execute('SELECT core.uuid_generate_v7() AS id')->fetch('assoc')['id'];
    }
}

/**
 * HTTP egress stub: records calls and returns a predetermined response.
 */
class FakeEgress extends EgressClient
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $calls = [];

    public function __construct(private int $status = 200)
    {
        parent::__construct(null, ['allow_private' => true]);
    }

    public function request(string $method, string $url, array $options = []): EgressResponse
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return new EgressResponse($this->status, [], 'ok');
    }
}
