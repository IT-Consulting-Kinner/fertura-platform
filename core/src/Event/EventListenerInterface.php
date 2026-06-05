<?php
declare(strict_types=1);

namespace App\Event;

/**
 * Vertrag für Event-Listener (Kap. 26.9). Module implementieren dieses Interface
 * in ihren Listener-Klassen; der Outbox-Worker ruft `handle()` auf.
 *
 * WICHTIG: Zustellung erfolgt mindestens einmal — Listener MÜSSEN idempotent
 * sein (Kap. 26.9.2). Der `$context['event_id']` dient zur Deduplizierung.
 */
interface EventListenerInterface
{
    /**
     * @param array<string, mixed> $payload Event-Nutzlast.
     * @param array<string, mixed> $context u. a. event_id, contract_name, attempt, correlation_id.
     */
    public function handle(array $payload, array $context): void;
}
