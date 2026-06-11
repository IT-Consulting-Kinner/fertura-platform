<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\I18n\LanguagePackStore;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Management of the managed locale store (i18n-3).
 *
 *   bin/cake lang recover   Heal/clean up orphaned .tmp files (recovery)
 *   bin/cake lang path      Show the store base directory
 */
class LangCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription('Managed Locale Store: Recovery/Diagnose.')
            ->addArgument('operation', ['choices' => ['recover', 'path'], 'required' => true]);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $store = new LanguagePackStore();

        if ($args->getArgument('operation') === 'path') {
            $io->out($store->base());

            return static::CODE_SUCCESS;
        }

        $r = $store->recover();
        $io->out(sprintf(
            'Recovery: %d geheilt, %d bereinigt, %d übersprungen (in-flight).',
            count($r['promoted']),
            count($r['cleaned']),
            count($r['skipped']),
        ));
        foreach ($r['promoted'] as $p) {
            $io->out('  geheilt:   ' . $p);
        }
        foreach ($r['cleaned'] as $c) {
            $io->out('  bereinigt: ' . $c);
        }

        return static::CODE_SUCCESS;
    }
}
