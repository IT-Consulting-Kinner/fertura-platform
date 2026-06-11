<?php
declare(strict_types=1);

namespace App\Service\Queue;

/**
 * Generic, durable job-queue transport (scaling/broker path, #10).
 *
 * Deliberately separate from the **event outbox**: domain events (with
 * automation/workflow/webhooks/retry/tenant fairness) stay DB-backed; this queue
 * is a thin primitive for **high-throughput/broker workloads**. Drivers: `db`
 * (default, Postgres `FOR UPDATE SKIP LOCKED`) or `redis` (Redis Streams,
 * consumer group). Selected via setting/env (`queue.transport`).
 */
interface QueueTransportInterface
{
    /**
     * Enqueues a job and returns its transport ID.
     *
     * @param array<string,mixed> $payload
     */
    public function push(string $queue, array $payload): string;

    /**
     * Reserves up to $max ready jobs (visible only to the caller until
     * ack/release). Competing consumers receive disjoint sets.
     *
     * @return list<array{id:string, payload:array<string,mixed>}>
     */
    public function reserve(string $queue, int $max = 1): array;

    /** Acknowledges successful processing (removes the job permanently). */
    public function ack(string $queue, string $id): void;

    /** Releases a reserved job for redelivery (failure). */
    public function release(string $queue, string $id): void;

    /** Number of not-yet-reserved (ready) jobs. */
    public function size(string $queue): int;

    /** Driver name (`db`|`redis`). */
    public function name(): string;
}
