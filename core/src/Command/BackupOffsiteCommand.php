<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Backup\OffsiteBackupService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Console-side off-site storage of backups (Programme Tier-2, P14).
 *
 *   bin/cake backup_offsite push /path/to/backup.zip
 *   bin/cake backup_offsite list
 *   bin/cake backup_offsite pull backup.zip --to /path/local.zip
 *   bin/cake backup_offsite rm backup.zip
 */
class BackupOffsiteCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('Backups ins Off-Site-Objekt-Storage laden/holen (P14).');
        $parser->addArgument('action', ['help' => 'push|list|pull|rm', 'required' => true]);
        $parser->addArgument('name', ['help' => 'Dateipfad (push) bzw. Backup-Name', 'required' => false]);
        $parser->addOption('to', ['help' => 'Lokaler Zielpfad (pull)']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $svc = new OffsiteBackupService();
        $action = (string)$args->getArgument('action');
        $name = (string)$args->getArgument('name');

        switch ($action) {
            case 'push':
                if ($name === '') {
                    $io->error('Backup-Dateipfad erforderlich.');

                    return self::CODE_ERROR;
                }
                $io->success('Hochgeladen: ' . $svc->upload($name));

                return self::CODE_SUCCESS;

            case 'list':
                foreach ($svc->list() as $p) {
                    $io->out($p);
                }

                return self::CODE_SUCCESS;

            case 'pull':
                $to = (string)$args->getOption('to');
                if ($name === '' || $to === '') {
                    $io->error('Backup-Name und --to erforderlich.');

                    return self::CODE_ERROR;
                }
                $svc->download($name, $to);
                $io->success("Heruntergeladen nach $to");

                return self::CODE_SUCCESS;

            case 'rm':
                if ($name === '') {
                    $io->error('Backup-Name erforderlich.');

                    return self::CODE_ERROR;
                }
                $svc->delete($name);
                $io->success('Gelöscht.');

                return self::CODE_SUCCESS;

            default:
                $io->error("Unbekannte Aktion: $action");

                return self::CODE_ERROR;
        }
    }
}
