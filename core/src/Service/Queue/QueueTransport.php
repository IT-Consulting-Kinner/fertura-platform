<?php
declare(strict_types=1);

namespace App\Service\Queue;

use App\Service\Settings\SettingsManager;
use Throwable;

/**
 * Factory für den konfigurierten {@see QueueTransportInterface} (#10). Treiber aus
 * Setting `queue.transport` (`db`|`redis`; Default `db`). Der DB-Treiber braucht
 * keine externe Infrastruktur; `redis` aktiviert den Redis-Streams-Broker.
 */
final class QueueTransport
{
    public static function make(?string $driver = null): QueueTransportInterface
    {
        $driver ??= self::configuredDriver();

        return $driver === 'redis' ? new RedisStreamTransport() : new DbQueueTransport();
    }

    private static function configuredDriver(): string
    {
        try {
            $d = (string)(new SettingsManager())->get('core', 'queue.transport', 'db');
        } catch (Throwable) {
            $d = 'db';
        }

        return $d === 'redis' ? 'redis' : 'db';
    }
}
