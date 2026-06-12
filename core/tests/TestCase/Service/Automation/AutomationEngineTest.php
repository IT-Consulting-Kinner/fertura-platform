<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Automation;

use App\Service\Automation\AutomationEngine;
use App\Service\Notification\NotificationService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Test of the automation engine (P12): event matching, condition evaluation,
 * action execution (notification via a stub).
 */
class AutomationEngineTest extends TestCase
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
        ConnectionManager::get('default')->execute("DELETE FROM automation_rules WHERE name LIKE 'zztest_%'");
    }

    private function addRule(string $event, array $condition, array $actions): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO automation_rules (name, event, condition, actions) '
            . 'VALUES (:n, :e, CAST(:c AS jsonb), CAST(:a AS jsonb))',
            ['n' => 'zztest_' . bin2hex(random_bytes(3)), 'e' => $event, 'c' => json_encode($condition), 'a' => json_encode($actions)],
        );
    }

    private function engine(StubNotifier $notifier): AutomationEngine
    {
        return new AutomationEngine(null, $notifier);
    }

    public function testFiresMatchingRuleAndNotifies(): void
    {
        $this->addRule(
            'zztest.order',
            ['field' => 'data.amount', 'op' => 'gte', 'value' => 100],
            [['type' => 'notify', 'user_id' => 'u1', 'title' => 'Große Bestellung {{data.amount}}']],
        );
        $notifier = new StubNotifier();

        $fired = $this->engine($notifier)->onEvent('zztest.order', ['user_id' => 'u1', 'data' => ['amount' => 150]]);

        $this->assertSame(1, $fired);
        $this->assertCount(1, $notifier->calls);
        $this->assertSame('u1', $notifier->calls[0]['userId']);
        $this->assertSame('Große Bestellung 150', $notifier->calls[0]['title']);
    }

    public function testConditionFalseDoesNotFire(): void
    {
        $this->addRule('zztest.order', ['field' => 'data.amount', 'op' => 'gte', 'value' => 100], [['type' => 'notify', 'user_id' => 'u1', 'title' => 'x']]);
        $notifier = new StubNotifier();

        $fired = $this->engine($notifier)->onEvent('zztest.order', ['data' => ['amount' => 50]]);

        $this->assertSame(0, $fired);
        $this->assertCount(0, $notifier->calls);
    }

    public function testWildcardEventMatch(): void
    {
        $this->addRule('zztest.*', [], [['type' => 'notify', 'user_id' => 'u1', 'title' => 'Wildcard']]);
        $notifier = new StubNotifier();

        $this->assertSame(1, $this->engine($notifier)->onEvent('zztest.anything', []));
        $this->assertSame(0, $this->engine($notifier)->onEvent('other.event', []));
    }
}

/** NotificationService stub: records notify() calls. */
class StubNotifier extends NotificationService
{
    /**
     * @var list<array<string,mixed>>
     */
    public array $calls = [];

    public function notify(string $userId, string $type, string $title, string $body = '', array $data = [], ?array $channels = null): string
    {
        $this->calls[] = ['userId' => $userId, 'type' => $type, 'title' => $title, 'body' => $body];

        return 'stub';
    }
}
