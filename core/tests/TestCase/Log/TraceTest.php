<?php
declare(strict_types=1);

namespace App\Test\TestCase\Log;

use App\Log\LogContext;
use App\Log\Trace;
use Cake\TestSuite\TestCase;

/**
 * Test des W3C-Trace-Helfers (P04): Parsen/Erzeugen, traceparent aus Kontext.
 */
class TraceTest extends TestCase
{
    protected function tearDown(): void
    {
        LogContext::clear();
        parent::tearDown();
    }

    public function testParsesValidTraceparent(): void
    {
        $tp = Trace::parseTraceparent('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');
        $this->assertSame('0af7651916cd43dd8448eb211c80319c', $tp['trace_id']);
        $this->assertSame('b7ad6b7169203331', $tp['parent_id']);
    }

    public function testRejectsInvalidTraceparent(): void
    {
        $this->assertNull(Trace::parseTraceparent('garbage'));
        $this->assertNull(Trace::parseTraceparent('00-tooShort-xx-01'));
    }

    public function testNewIdsHaveCorrectLength(): void
    {
        $this->assertSame(32, strlen(Trace::newTraceId()));
        $this->assertSame(16, strlen(Trace::newSpanId()));
    }

    public function testTraceparentFromContext(): void
    {
        $this->assertNull(Trace::traceparent(), 'ohne Kontext kein traceparent');
        LogContext::put('trace_id', str_repeat('a', 32));
        LogContext::put('span_id', str_repeat('b', 16));
        $this->assertSame('00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01', Trace::traceparent());
    }
}
