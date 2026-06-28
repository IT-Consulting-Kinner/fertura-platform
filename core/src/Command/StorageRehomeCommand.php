<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Storage\StorageRehomer;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * One-time operator migration: re-home a module's LEGACY files into the per-tenant
 * convention `tenant/<id>/<module-key>/…` (Inc 8).
 *
 *   bin/cake storage_rehome --module ticketing --plan plan.json [--dry-run] \
 *       [--delete-source] [--overwrite] [--out results.json]
 *
 * The MODULE produces `plan.json` from its own database — a JSON array of
 * `{ "tenant_id": "<uuid>", "source": "<old storage path>", "target": "<relpath>" }`
 * (target is the path UNDER the tenant/module subtree). The Core moves the bytes
 * (privileged, verify-before-delete, idempotent) and writes `results.json`; the
 * module then updates its path columns from the results. The Core never touches the
 * module's database.
 */
class StorageRehomeCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription(
            'Einmalige Operator-Migration: Modul-Altdateien in die Konvention tenant/<id>/<key>/ umlegen (Inc 8).',
        );
        $parser->addOption('module', ['help' => 'Modul-Key (z. B. ticketing)', 'required' => true]);
        $parser->addOption('plan', ['help' => 'Pfad zur Plan-JSON ([{tenant_id, source, target}, …])', 'required' => true]);
        $parser->addOption('out', ['help' => 'Pfad für die Ergebnis-JSON (für das DB-Pfad-Update durch das Modul).']);
        $parser->addOption('dry-run', ['boolean' => true, 'help' => 'Nur berichten, nichts schreiben/löschen.']);
        $parser->addOption('delete-source', ['boolean' => true, 'help' => 'Quelle nach verifiziertem Schreiben entfernen.']);
        $parser->addOption('overwrite', ['boolean' => true, 'help' => 'Vorhandenes Ziel überschreiben (statt Konflikt).']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $module = (string)$args->getOption('module');
        $planPath = (string)$args->getOption('plan');
        if (!is_file($planPath)) {
            $io->error("Plan-Datei nicht gefunden: $planPath");

            return self::CODE_ERROR;
        }
        $plan = json_decode((string)file_get_contents($planPath), true);
        if (!is_array($plan) || !array_is_list($plan)) {
            $io->error('Plan-JSON muss ein Array von Einträgen sein ([{tenant_id, source, target}, …]).');

            return self::CODE_ERROR;
        }

        $dry = (bool)$args->getOption('dry-run');
        $opts = [
            'dryRun' => $dry,
            'deleteSource' => (bool)$args->getOption('delete-source'),
            'overwrite' => (bool)$args->getOption('overwrite'),
        ];

        $io->out(($dry ? '[dry-run] ' : '') . "Re-Homing '$module' — " . count($plan) . ' Eintrag/Einträge …');
        $results = (new StorageRehomer())->rehome($module, $plan, $opts);

        // Summary per status + surface every error line.
        $counts = [];
        $errors = 0;
        foreach ($results as $r) {
            $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
            if (StorageRehomer::isError($r['status'])) {
                $errors++;
                $io->error('FEHLER: ' . ($r['error'] !== '' ? $r['error'] : $r['source']));
            }
        }
        foreach ($counts as $status => $n) {
            $io->out(sprintf('  %-14s %d', $status, $n));
        }

        $out = (string)($args->getOption('out') ?? '');
        if ($out !== '') {
            file_put_contents($out, (string)json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $io->out("Ergebnisse geschrieben: $out");
        }

        if ($errors > 0) {
            $io->error("$errors Eintrag/Einträge benötigen Aufmerksamkeit (siehe oben).");

            return self::CODE_ERROR;
        }
        $io->success('Re-Homing abgeschlossen.');

        return self::CODE_SUCCESS;
    }
}
