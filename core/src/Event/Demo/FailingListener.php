<?php
declare(strict_types=1);

namespace App\Event\Demo;

use App\Event\EventListenerInterface;
use RuntimeException;

/**
 * Demo-/Test-Listener: schlägt immer fehl (für Retry/Dead-Letter/Isolation).
 * Nur vom Outbox-Selbsttest verwendet.
 */
class FailingListener implements EventListenerInterface
{
    public function handle(array $payload, array $context): void
    {
        throw new RuntimeException('absichtlicher Listener-Fehler');
    }
}
