<?php
declare(strict_types=1);

namespace App\Service\System;

use function Cake\Core\env;

/**
 * Deployment feature flags (ch. 23.3 — a lean core per installation).
 *
 * Optional subsystems can be turned off **per deployment** via the environment
 * variables `FEATURE_<NAME>`, so each installation runs only what it needs
 * (smaller attack/maintenance surface). Deliberately **env-based** (not a DB
 * setting): a hard operator switch that cannot be re-enabled through a
 * compromised admin session. Default: all active (backward-compatible).
 *
 * Values: anything other than `false`/`0`/`off`/`no`/`disabled` (case-insensitive)
 * counts as active; if the variable is unset, the default applies.
 */
final class FeatureFlags
{
    /** Known flags and their default (true = active). */
    public const DEFAULTS = [
        'api' => true, // External API v1 (/api/v1, bearer token)
        'marketplace' => true, // Marketplace client/sync (outbound calls)
        'backup_scheduler' => true, // Automatic (scheduled) backups
    ];

    public static function enabled(string $feature): bool
    {
        $default = self::DEFAULTS[$feature] ?? true;
        $raw = trim((string)env('FEATURE_' . strtoupper($feature)));
        if ($raw === '') {
            return $default;
        }

        return !in_array(strtolower($raw), ['0', 'false', 'off', 'no', 'disabled'], true);
    }

    /** @return array<string, bool> */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $feature) {
            $out[$feature] = self::enabled($feature);
        }

        return $out;
    }
}
