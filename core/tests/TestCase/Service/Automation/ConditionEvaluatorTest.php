<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Automation;

use App\Service\Automation\ConditionEvaluator;
use Cake\TestSuite\TestCase;

/**
 * Test des Bedingungs-Evaluators (P12): Operatoren, Verschachtelung, Feldpfade.
 */
class ConditionEvaluatorTest extends TestCase
{
    private ConditionEvaluator $e;

    protected function setUp(): void
    {
        parent::setUp();
        $this->e = new ConditionEvaluator();
    }

    private function ctx(): array
    {
        return ['user_id' => 'u1', 'data' => ['priority' => 'high', 'amount' => 150, 'tags' => ['a', 'b']]];
    }

    public function testEmptyIsTrue(): void
    {
        $this->assertTrue($this->e->evaluate([], $this->ctx()));
    }

    public function testLeafOperators(): void
    {
        $c = $this->ctx();
        $this->assertTrue($this->e->evaluate(['field' => 'data.priority', 'op' => 'eq', 'value' => 'high'], $c));
        $this->assertFalse($this->e->evaluate(['field' => 'data.priority', 'op' => 'eq', 'value' => 'low'], $c));
        $this->assertTrue($this->e->evaluate(['field' => 'data.amount', 'op' => 'gte', 'value' => 100], $c));
        $this->assertFalse($this->e->evaluate(['field' => 'data.amount', 'op' => 'lt', 'value' => 100], $c));
        $this->assertTrue($this->e->evaluate(['field' => 'data.priority', 'op' => 'in', 'value' => ['high', 'urgent']], $c));
        $this->assertTrue($this->e->evaluate(['field' => 'data.priority', 'op' => 'contains', 'value' => 'hig'], $c));
        $this->assertTrue($this->e->evaluate(['field' => 'data.missing', 'op' => 'exists', 'value' => false], $c));
        $this->assertTrue($this->e->evaluate(['field' => 'data.amount', 'op' => 'exists', 'value' => true], $c));
    }

    public function testAllAnyNot(): void
    {
        $c = $this->ctx();
        $this->assertTrue($this->e->evaluate([
            'all' => [
                ['field' => 'data.priority', 'op' => 'eq', 'value' => 'high'],
                ['field' => 'data.amount', 'op' => 'gte', 'value' => 100],
            ],
        ], $c));
        $this->assertFalse($this->e->evaluate([
            'all' => [
                ['field' => 'data.priority', 'op' => 'eq', 'value' => 'high'],
                ['field' => 'data.amount', 'op' => 'gte', 'value' => 1000],
            ],
        ], $c));
        $this->assertTrue($this->e->evaluate([
            'any' => [
                ['field' => 'data.amount', 'op' => 'gte', 'value' => 1000],
                ['field' => 'data.priority', 'op' => 'eq', 'value' => 'high'],
            ],
        ], $c));
        $this->assertTrue($this->e->evaluate(['not' => ['field' => 'data.priority', 'op' => 'eq', 'value' => 'low']], $c));
    }

    public function testMissingFieldIsNull(): void
    {
        $this->assertFalse($this->e->evaluate(['field' => 'nope.deep', 'op' => 'eq', 'value' => 'x'], $this->ctx()));
    }
}
