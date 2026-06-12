<?php
declare(strict_types=1);

namespace IsolatedAnon;

use App\Service\Schedule\ScheduledTaskInterface;
use Cake\Datasource\ConnectionManager;

/**
 * Periodic task of the isolated module (phase 3): writes a marker into its own
 * table on every run — runs in the isolated host under the module role
 * (ch. 23.16.2).
 */
class PingTask implements ScheduledTaskInterface
{
    public function key(): string
    {
        return 'isolated_anon.ping';
    }

    public function intervalSeconds(): int
    {
        return 0; // always due in the test
    }

    public function run(): void
    {
        ConnectionManager::get('default')->execute(
            "INSERT INTO user_data (note) VALUES ('ticked')",
        );
    }
}
