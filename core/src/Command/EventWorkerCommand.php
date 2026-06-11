<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Event\OutboxWorker;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

/**
 * Long-running outbox worker (Step 6). Run by the worker container.
 * LISTEN/NOTIFY + poll fallback; SIGTERM/SIGINT stop after the current batch.
 */
class EventWorkerCommand extends Command
{
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $io->out('Outbox-Worker startet ...');
        $worker = new OutboxWorker(logger: function (string $m) use ($io): void {
            $io->out('[' . date('H:i:s') . '] ' . $m);
        });

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stop = function () use ($worker, $io): void {
                $io->out('Signal empfangen -> stoppe nach aktuellem Batch.');
                $worker->stop();
            };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }

        $worker->run();

        return static::CODE_SUCCESS;
    }
}
