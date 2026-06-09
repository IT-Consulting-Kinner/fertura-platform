<?php
declare(strict_types=1);

namespace App\Log;

/**
 * W3C-Trace-Context-Helfer (Programm Tier-3, P04).
 *
 * Erzeugt/parst Trace-/Span-IDs und den `traceparent`-Header, sodass Logzeilen
 * (über {@see LogContext}/`ContextJsonFormatter`) ein `trace_id`/`span_id`
 * tragen und ausgehende Aufrufe (Egress) den Trace fortführen können.
 */
final class Trace
{
    public static function newTraceId(): string
    {
        return bin2hex(random_bytes(16)); // 16 Byte -> 32 hex
    }

    public static function newSpanId(): string
    {
        return bin2hex(random_bytes(8)); // 8 Byte -> 16 hex
    }

    /**
     * Parst einen W3C-`traceparent` (`00-<32hex>-<16hex>-<2hex>`).
     *
     * @return array{trace_id:string, parent_id:string}|null
     */
    public static function parseTraceparent(string $header): ?array
    {
        if (!preg_match('/^[0-9a-f]{2}-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/i', trim($header), $m)) {
            return null;
        }

        return ['trace_id' => strtolower($m[1]), 'parent_id' => strtolower($m[2])];
    }

    /**
     * @return array{trace_id:string, span_id:string}
     */
    public static function current(): array
    {
        $ctx = LogContext::all();

        return [
            'trace_id' => (string)($ctx['trace_id'] ?? ''),
            'span_id' => (string)($ctx['span_id'] ?? ''),
        ];
    }

    /** Baut den `traceparent`-Header aus dem aktuellen Kontext (oder null). */
    public static function traceparent(): ?string
    {
        $c = self::current();
        if ($c['trace_id'] === '' || $c['span_id'] === '') {
            return null;
        }

        return '00-' . $c['trace_id'] . '-' . $c['span_id'] . '-01';
    }
}
