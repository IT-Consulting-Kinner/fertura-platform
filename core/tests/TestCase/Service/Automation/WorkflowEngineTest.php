<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Automation;

use App\Service\Automation\WorkflowEngine;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Test of the workflow state machine (P12 extension): state transitions,
 * from-state gating, condition gating, wildcard transition.
 */
class WorkflowEngineTest extends TestCase
{
    private string $defId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $transitions = [
            ['from' => 'new', 'on_event' => 'order.paid', 'to' => 'paid'],
            ['from' => 'paid', 'on_event' => 'order.shipped', 'to' => 'shipped', 'condition' => ['field' => 'data.carrier', 'op' => 'exists', 'value' => true]],
            ['from' => '*', 'on_event' => 'order.cancelled', 'to' => 'cancelled'],
        ];
        $this->defId = (string)ConnectionManager::get('default')->execute(
            'INSERT INTO workflow_definitions (name, entity_type, entity_id_field, initial_state, transitions) '
            . "VALUES ('zztest_order', 'order', 'data.order_id', 'new', CAST(:t AS jsonb)) RETURNING id",
            ['t' => json_encode($transitions)],
        )->fetch('assoc')['id'];
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        ConnectionManager::get('default')->execute("DELETE FROM workflow_definitions WHERE name LIKE 'zztest_%'");
    }

    public function testTransitionsAdvanceState(): void
    {
        $wf = new WorkflowEngine();

        $this->assertSame(1, $wf->onEvent('order.paid', ['data' => ['order_id' => 'o1']]));
        $this->assertSame('paid', $wf->stateOf($this->defId, 'o1'));

        $this->assertSame(1, $wf->onEvent('order.shipped', ['data' => ['order_id' => 'o1', 'carrier' => 'DHL']]));
        $this->assertSame('shipped', $wf->stateOf($this->defId, 'o1'));
    }

    public function testFromStateGating(): void
    {
        $wf = new WorkflowEngine();
        // New order o2 starts in 'new' -> 'order.shipped' (from 'paid') does not apply.
        $this->assertSame(0, $wf->onEvent('order.shipped', ['data' => ['order_id' => 'o2', 'carrier' => 'DHL']]));
        $this->assertSame('new', $wf->stateOf($this->defId, 'o2'));
    }

    public function testConditionGating(): void
    {
        $wf = new WorkflowEngine();
        $wf->onEvent('order.paid', ['data' => ['order_id' => 'o3']]);
        // Without carrier -> condition exists=false not met -> no transition.
        $this->assertSame(0, $wf->onEvent('order.shipped', ['data' => ['order_id' => 'o3']]));
        $this->assertSame('paid', $wf->stateOf($this->defId, 'o3'));
    }

    public function testWildcardTransition(): void
    {
        $wf = new WorkflowEngine();
        $wf->onEvent('order.paid', ['data' => ['order_id' => 'o4']]);
        $this->assertSame(1, $wf->onEvent('order.cancelled', ['data' => ['order_id' => 'o4']]));
        $this->assertSame('cancelled', $wf->stateOf($this->defId, 'o4'));
    }
}
