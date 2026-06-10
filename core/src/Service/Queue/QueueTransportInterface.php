<?php
declare(strict_types=1);

namespace App\Service\Queue;

/**
 * Generischer, durabler Job-Queue-Transport (Skalierungs-/Broker-Pfad, #10).
 *
 * Bewusst getrennt vom **Event-Outbox**: Domänen-Events (mit Automation/Workflow/
 * Webhooks/Retry/Mandanten-Fairness) bleiben DB-gestützt; diese Queue ist ein
 * schmales Primitiv für **durchsatzstarke/Broker-Workloads**. Treiber: `db`
 * (Default, Postgres `FOR UPDATE SKIP LOCKED`) oder `redis` (Redis Streams,
 * Consumer-Group). Auswahl über Setting/Env (`queue.transport`).
 */
interface QueueTransportInterface
{
    /** Reiht einen Job ein und gibt seine Transport-ID zurück. */
    public function push(string $queue, array $payload): string;

    /**
     * Reserviert bis zu $max bereitstehende Jobs (sichtbar nur dem Aufrufer, bis
     * ack/release). Konkurrierende Consumer erhalten disjunkte Mengen.
     *
     * @return list<array{id:string, payload:array<string,mixed>}>
     */
    public function reserve(string $queue, int $max = 1): array;

    /** Bestätigt die erfolgreiche Verarbeitung (entfernt den Job endgültig). */
    public function ack(string $queue, string $id): void;

    /** Gibt einen reservierten Job zur erneuten Zustellung frei (Fehlschlag). */
    public function release(string $queue, string $id): void;

    /** Anzahl noch nicht reservierter (bereitstehender) Jobs. */
    public function size(string $queue): int;

    /** Treibername (`db`|`redis`). */
    public function name(): string;
}
