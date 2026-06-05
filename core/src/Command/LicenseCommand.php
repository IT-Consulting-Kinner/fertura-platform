<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\License\LicenseService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use RuntimeException;

/**
 * Lizenzverwaltung (Kap. 28.7).
 *
 *   bin/cake license install <lizenzdatei>
 *   bin/cake license check <module_key>
 */
class LicenseCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->addArgument('operation', ['choices' => ['install', 'check'], 'required' => true])
            ->addArgument('target', ['help' => 'Lizenzdatei (install) oder module_key (check)', 'required' => true]);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $service = new LicenseService();
        $op = (string)$args->getArgument('operation');
        $target = (string)$args->getArgument('target');

        try {
            if ($op === 'install') {
                if (!is_file($target)) {
                    $io->error("Lizenzdatei nicht gefunden: $target");

                    return static::CODE_ERROR;
                }
                $result = $service->install((string)file_get_contents($target));
                $io->success('Lizenz installiert. Status: ' . $result['status']);
            } else {
                $ev = $service->evaluate($target);
                $io->out('Status: ' . $ev['status'] . (isset($ev['reason']) ? ' (' . $ev['reason'] . ')' : ''));
            }
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());

            return static::CODE_ERROR;
        }

        return static::CODE_SUCCESS;
    }
}
