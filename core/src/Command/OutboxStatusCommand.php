<?php
declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Datasource\ConnectionManager;

/**
 * Shows the outbox state (counts per status) and the most recent dead-letter
 * events (interim visibility until the admin GUI in Step 10; health endpoint
 * in Step 12).
 */
class OutboxStatusCommand extends Command
{
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $connection = ConnectionManager::get('default');

        $counts = $connection->execute(
            'SELECT status, count(*) AS c FROM event_outbox GROUP BY status ORDER BY status',
        )->fetchAll('assoc');

        $io->out('<info>Outbox-Stand:</info>');
        if (!$counts) {
            $io->out('  (leer)');
        }
        foreach ($counts as $row) {
            $io->out(sprintf('  %-12s %s', $row['status'], $row['c']));
        }

        $deadLetters = $connection->execute(
            "SELECT id, contract_name, attempt_count, left(coalesce(last_error,''), 80) AS err "
            . "FROM event_outbox WHERE status = 'dead_letter' ORDER BY updated_at DESC LIMIT 10",
        )->fetchAll('assoc');

        if ($deadLetters) {
            $io->out('');
            $io->out('<warning>Dead-Letter (letzte 10):</warning>');
            foreach ($deadLetters as $d) {
                $io->out(sprintf('  %s  %s  (%sx)  %s', $d['id'], $d['contract_name'], $d['attempt_count'], $d['err']));
            }
        }

        return static::CODE_SUCCESS;
    }
}
