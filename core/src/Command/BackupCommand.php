<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Backup\BackupService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Core-Backup (Kap. 20.1 / E53).
 *
 *   bin/cake backup create [--note "..."]
 *   bin/cake backup list
 *   bin/cake backup verify <id>           Checksummen prüfen
 *   bin/cake backup test-restore <id>     Probe-Restore in Scratch-DB (prüfbar)
 *   bin/cake backup restore <id> --yes    DESTRUKTIV: Produktion wiederherstellen
 *   bin/cake backup delete <id>
 */
class BackupCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->addArgument('operation', [
                'choices' => ['create', 'list', 'verify', 'test-restore', 'restore', 'delete'],
                'required' => true,
            ])
            ->addArgument('id', ['help' => 'Backup-ID'])
            ->addOption('note', ['help' => 'Notiz (bei create)'])
            ->addOption('yes', ['boolean' => true, 'help' => 'Bestätigt die destruktive Wiederherstellung']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $svc = new BackupService();
        $op = (string)$args->getArgument('operation');
        $id = (string)($args->getArgument('id') ?? '');

        switch ($op) {
            case 'create':
                $io->out('Erstelle Sicherung (unter Lifecycle-Lock) …');
                $newId = $svc->create($args->getOption('note'), null);
                $io->success('Backup erstellt: ' . $newId);

                return static::CODE_SUCCESS;

            case 'list':
                foreach ($svc->list() as $b) {
                    $io->out(sprintf(
                        '  %s  %s  %-9s db=%s files=%s  %s',
                        $b['id'],
                        (string)$b['created_at'],
                        (string)$b['status'],
                        $this->human((int)$b['db_bytes']),
                        $this->human((int)$b['files_bytes']),
                        (string)($b['note'] ?? ''),
                    ));
                }

                return static::CODE_SUCCESS;

            case 'verify':
                $v = $svc->verify($id);
                $io->out('db=' . ($v['db'] ? 'ok' : 'FEHLER') . ' files=' . ($v['files'] ? 'ok' : 'FEHLER'));
                $v['ok'] ? $io->success('Backup integer.') : $io->error('Backup fehlerhaft: ' . ($v['reason'] ?? ''));

                return $v['ok'] ? static::CODE_SUCCESS : static::CODE_ERROR;

            case 'test-restore':
                $io->out('Probe-Restore in Scratch-Datenbank …');
                $t = $svc->testRestore($id);
                if ($t['ok']) {
                    $io->success("Restore-Probe erfolgreich ({$t['tables']} core-Tabellen).");

                    return static::CODE_SUCCESS;
                }
                $io->error('Restore-Probe fehlgeschlagen: ' . ($t['reason'] ?? ''));

                return static::CODE_ERROR;

            case 'restore':
                if (!$args->getOption('yes')) {
                    $io->error('Destruktiv: überschreibt DB + Datei-Stores. Mit --yes bestätigen.');

                    return static::CODE_ERROR;
                }
                $io->warning('Stelle Produktion wieder her …');
                $svc->restore($id);
                $io->success('Wiederhergestellt. Empfehlung: Dienste neu starten.');

                return static::CODE_SUCCESS;

            case 'delete':
                $svc->delete($id) ? $io->success('Backup gelöscht.') : $io->error('Backup unbekannt.');

                return static::CODE_SUCCESS;
        }

        return static::CODE_ERROR;
    }

    private function human(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0';
        }
        $u = ['B', 'K', 'M', 'G'];
        $i = (int)floor(log($bytes, 1024));
        $i = min($i, count($u) - 1);

        return round($bytes / 1024 ** $i, 1) . $u[$i];
    }
}
