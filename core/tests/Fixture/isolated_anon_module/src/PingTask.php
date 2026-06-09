<?php
declare(strict_types=1);

namespace IsolatedAnon;

use App\Service\Schedule\ScheduledTaskInterface;
use Cake\Datasource\ConnectionManager;

/**
 * Periodische Aufgabe des isolierten Moduls (Phase 3): schreibt bei jedem Lauf
 * einen Marker in die eigene Tabelle — läuft im isolierten Host über die
 * Modul-Rolle (Kap. 23.16.2).
 */
class PingTask implements ScheduledTaskInterface
{
    public function key(): string
    {
        return 'isolated_anon.ping';
    }

    public function intervalSeconds(): int
    {
        return 0; // im Test immer fällig
    }

    public function run(): void
    {
        ConnectionManager::get('default')->execute(
            "INSERT INTO user_data (note) VALUES ('ticked')",
        );
    }
}
