<?php
declare(strict_types=1);

namespace App\Service\Storage;

/**
 * The currently-dispatching MODULE's key, while a (non-core) module contribution runs
 * in-process (Inc 8b). {@see \App\Service\Module\ContributionRuntime::call} sets it
 * around every in-process module invocation (web/api/listener/collector/task — the one
 * dispatch chokepoint) and restores the previous value afterwards, so it is correct
 * even for a module that, via a capability, calls into another module (nesting).
 *
 * {@see StorageManager} consults it to REFUSE a write that lands outside the active
 * module's per-tenant subtree `tenant/<id>/<key>/` — turning the "module forgot the
 * file convention" bug from silent data loss (backups miss the bytes, billing
 * under-counts) into a loud, immediate error. Core's own code runs with NO active
 * scope (its contributions carry module_key 'core'), so it is never restricted.
 */
final class ModuleStorageScope
{
    private static ?string $moduleKey = null;

    /**
     * Enters the dispatch scope of $moduleKey and returns the PREVIOUS value, which
     * the caller must hand back to {@see self::leave()} in a finally block so nested
     * dispatches restore correctly.
     */
    public static function enter(string $moduleKey): ?string
    {
        $previous = self::$moduleKey;
        self::$moduleKey = $moduleKey;

        return $previous;
    }

    /** Restores the scope captured by the matching {@see self::enter()}. */
    public static function leave(?string $previous): void
    {
        self::$moduleKey = $previous;
    }

    /** The active module key, or null when no module contribution is dispatching. */
    public static function active(): ?string
    {
        return self::$moduleKey;
    }
}
