<?php
declare(strict_types=1);

namespace App\Service\Queue;

use App\Service\Settings\SettingsManager;
use Throwable;

/**
 * Factory for the configured {@see QueueTransportInterface} (#10). Driver from the
 * setting `queue.transport` (`db`|`redis`; default `db`). The DB driver needs no
 * external infrastructure; `redis` enables the Redis Streams broker.
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
