<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Search\SearchService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Such-Wartung: Modul-Indexer neu anstoßen und/oder fehlende Embeddings für die
 * Hybrid-Suche nachziehen (Bestandsdaten, die vor `ai.embed.auto_index` indexiert
 * wurden).
 */
class SearchReindexCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription('Volltext-Indexer neu anstoßen und/oder fehlende Embeddings nachziehen.')
            ->addOption('embeddings', [
                'help' => 'Nur fehlende Embeddings für bereits indexierte Dokumente nachziehen (Backfill).',
                'boolean' => true,
            ])
            ->addOption('limit', [
                'help' => 'Maximale Anzahl Dokumente für den Embedding-Backfill (Default 500).',
                'default' => '500',
            ]);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $svc = new SearchService();

        if (!$args->getOption('embeddings')) {
            $indexers = $svc->reindexAll();
            $io->success("Volltext-Reindex angestoßen: $indexers Indexer.");
        }

        $n = $svc->backfillEmbeddings((int)$args->getOption('limit'));
        $io->out("Embeddings nachgezogen: $n Dokument(e).");

        return self::CODE_SUCCESS;
    }
}
