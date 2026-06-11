<?php
declare(strict_types=1);

namespace App\Event\Demo;

use App\Event\EventListenerInterface;
use RuntimeException;

/**
 * Demo/test listener: always fails (for retry/dead-letter/isolation).
 * Used only by the outbox self-test.
 */
class FailingListener implements EventListenerInterface
{
    public function handle(array $payload, array $context): void
    {
        throw new RuntimeException('absichtlicher Listener-Fehler');
    }
}
