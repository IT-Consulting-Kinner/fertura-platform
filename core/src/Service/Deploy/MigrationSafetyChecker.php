<?php
declare(strict_types=1);

namespace App\Service\Deploy;

/**
 * Heuristic migration safety check (program tier-3, P15): finds
 * **destructive/backwards-incompatible** patterns in migrations that break a
 * zero-downtime deployment (rolling/blue-green), and recommends the
 * **expand/contract** pattern.
 *
 * Scans only the `up()` path (the `down()` rollback is allowed to drop).
 * Advisory — the operator decides.
 */
class MigrationSafetyChecker
{
    /** @var list<array{0:string,1:string,2:string}> [regex, issue, hint] */
    private const RULES = [
        ['/DROP\s+TABLE/i', 'DROP TABLE', 'Erst Code entkoppeln, in späterem Release droppen (contract).'],
        ['/DROP\s+COLUMN/i', 'DROP COLUMN', 'Spalte erst ungenutzt machen, später droppen (contract).'],
        ['/RENAME\s+(TO|COLUMN)/i', 'RENAME', 'Bricht laufenden Code — neue Spalte + Backfill statt Rename (expand).'],
        ['/ALTER\s+COLUMN\s+\w+\s+(SET\s+DATA\s+)?TYPE/i', 'ALTER COLUMN TYPE', 'Möglicher Tabellen-Rewrite/Inkompatibilität — additive Spalte bevorzugen.'],
        ['/ADD\s+COLUMN\b(?![^;]*\bDEFAULT\b)[^;]*\bNOT\s+NULL\b/i', 'NOT NULL ohne DEFAULT', 'Bricht laufende Inserts — erst nullable + Backfill, Constraint später.'],
        ['/\bTRUNCATE\b/i', 'TRUNCATE', 'Datenverlust.'],
    ];

    /**
     * @return list<array{file:string,line:int,issue:string,hint:string}>
     */
    public function check(string $dir): array
    {
        $findings = [];
        foreach (glob(rtrim($dir, '/') . '/*.php') ?: [] as $file) {
            $lines = file($file) ?: [];
            foreach ($lines as $i => $line) {
                // Stop checking once the down() rollback begins (drops are expected there).
                if (preg_match('/function\s+down\s*\(/i', $line)) {
                    break;
                }
                foreach (self::RULES as [$regex, $issue, $hint]) {
                    if (preg_match($regex, $line)) {
                        $findings[] = ['file' => basename($file), 'line' => $i + 1, 'issue' => $issue, 'hint' => $hint];
                    }
                }
            }
        }

        return $findings;
    }
}
