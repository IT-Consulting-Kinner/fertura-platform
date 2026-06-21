<?php
declare(strict_types=1);

namespace App\Service\System;

/**
 * One critical-action type's execute / verify / rollback logic — Phase 6
 * (docs/maintenance-mode-design.md §4.2). The {@see CriticalActionRunner} drives a
 * queued action through the protected sequence and delegates the type-specific work
 * here. Handlers are Core-internal (registered in {@see CriticalActionRegistry}), so
 * the Core-never-edits-modules boundary is preserved.
 */
interface CriticalActionHandler
{
    /** The `core.critical_action.type` this handler runs. */
    public function type(): string;

    /**
     * Performs the mutation. MUST throw on any failure (the runner then rolls back).
     *
     * @param array<string,mixed> $payload the action parameters
     */
    public function execute(array $payload): void;

    /**
     * Post-action verification. The runner rolls back when ok is false.
     *
     * @param array<string,mixed> $payload
     * @return array{ok:bool, reason:?string}
     */
    public function verify(array $payload): array;

    /**
     * Action-own undo of {@see execute()} (the PRIMARY rollback per design §3).
     * Best-effort + idempotent; MUST throw if the undo itself could not complete, so
     * the runner escalates to `needs_manual_restore` (operator restores the
     * pre-action backup via CLI — global restore is not GUI-reachable, decision #7).
     *
     * @param array<string,mixed> $payload
     */
    public function rollback(array $payload): void;
}
