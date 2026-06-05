<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Security\TrustStore;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;

/**
 * Verwaltung von Vertrauensankern und Sperrliste (Kap. 24.9.2).
 *
 *   bin/cake trust add-anchor <key_id> <public_key_b64> [--type root|publisher] [--publisher P]
 *   bin/cake trust revoke <key_id> [--reason R]
 *   bin/cake trust list
 */
class TrustCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->addArgument('operation', ['choices' => ['add-anchor', 'revoke', 'list'], 'required' => true])
            ->addArgument('key_id', ['help' => 'Schlüssel-ID'])
            ->addArgument('public_key', ['help' => 'Public Key (base64, nur add-anchor)'])
            ->addOption('type', ['choices' => ['root', 'publisher'], 'default' => 'root'])
            ->addOption('publisher', ['help' => 'Publisher (für publisher-Keys)'])
            ->addOption('reason', ['help' => 'Widerrufsgrund']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $trust = new TrustStore();

        switch ($args->getArgument('operation')) {
            case 'add-anchor':
                $trust->addAnchor(
                    (string)$args->getArgument('key_id'),
                    (string)$args->getArgument('public_key'),
                    (string)$args->getOption('type'),
                    $args->getOption('publisher'),
                );
                $io->success('Vertrauensanker hinzugefügt: ' . $args->getArgument('key_id'));

                return static::CODE_SUCCESS;

            case 'revoke':
                $trust->revokeKey((string)$args->getArgument('key_id'), $args->getOption('reason'));
                $io->success('Schlüssel widerrufen: ' . $args->getArgument('key_id'));

                return static::CODE_SUCCESS;

            case 'list':
                $rows = ConnectionManager::get('default')->execute(
                    'SELECT key_id, key_type, publisher, active FROM trust_anchors ORDER BY key_id',
                )->fetchAll('assoc');
                foreach ($rows as $r) {
                    $io->out(sprintf('  %-16s %-10s %s%s', $r['key_id'], $r['key_type'], $r['publisher'] ?? '-', $r['active'] ? '' : ' [inaktiv]'));
                }
                $revoked = ConnectionManager::get('default')->execute('SELECT key_id FROM revoked_keys')->fetchAll('assoc');
                foreach ($revoked as $r) {
                    $io->out('  <warning>widerrufen:</warning> ' . $r['key_id']);
                }

                return static::CODE_SUCCESS;
        }

        return static::CODE_ERROR;
    }
}
