<?php
declare(strict_types=1);

namespace App\Service\Schedule;

/**
 * Periodic module task (extension point, ch. 20.3 / 20.4).
 *
 * Modules (e.g. ticketing: `fetch_mails`, `check_escalations`) register
 * implementations via `collectors_registered` for the collector
 * `core.collector.scheduled` in the manifest. The core worker ticks them at the
 * configured interval (error-isolated, with heartbeat). The business logic stays
 * in the module.
 */
interface ScheduledTaskInterface
{
    /** Unique, stable key (e.g. `ticketing.fetch_mails`). */
    public function key(): string;

    /** Minimum interval between two runs, in seconds. */
    public function intervalSeconds(): int;

    /** Runs the task. Exceptions are isolated/logged by the runner. */
    public function run(): void;
}
