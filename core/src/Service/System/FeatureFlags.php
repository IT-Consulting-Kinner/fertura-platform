<?php
declare(strict_types=1);

namespace App\Service\System;

use function Cake\Core\env;

/**
 * Deployment-Feature-Flags (Kap. 23.3 — schlanker Core je Installation).
 *
 * Optionale Subsysteme lassen sich **pro Deployment** über Umgebungsvariablen
 * `FEATURE_<NAME>` abschalten, sodass je Installation nur das Nötige läuft
 * (kleinere Angriffs-/Wartungsfläche). Bewusst **env-basiert** (nicht als
 * DB-Setting): ein harter Betreiber-Schalter, der nicht über eine kompromittierte
 * Admin-Sitzung wieder aktiviert werden kann. Standard: alle aktiv (kompatibel).
 *
 * Werte: alles außer `false`/`0`/`off`/`no`/`disabled` (case-insensitiv) gilt als
 * aktiv; ist die Variable nicht gesetzt, greift der Default.
 */
final class FeatureFlags
{
    /** Bekannte Flags und ihr Default (true = aktiv). */
    public const DEFAULTS = [
        'api' => true,              // Externe API v1 (/api/v1, Bearer-Token)
        'marketplace' => true,      // Marketplace-Client/-Sync (ausgehende Aufrufe)
        'backup_scheduler' => true, // Automatische (geplante) Backups
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
