<?php
declare(strict_types=1);

namespace App\Event;

/**
 * Contract for event listeners (ch. 26.9). Modules implement this interface in
 * their listener classes; the outbox worker calls `handle()`.
 *
 * IMPORTANT: delivery is at-least-once — listeners MUST be idempotent
 * (ch. 26.9.2). The `$context['event_id']` is used for deduplication.
 */
interface EventListenerInterface
{
    /**
     * @param array<string, mixed> $payload Event payload.
     * @param array<string, mixed> $context includes event_id, contract_name, attempt, correlation_id, among others.
     */
    public function handle(array $payload, array $context): void;
}
