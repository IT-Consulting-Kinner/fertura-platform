<?php
declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Datasource\ConnectionManager;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Stellt monatliche Partitionen für core.audit_log sicher (Vormonat, aktueller
 * Monat, Folgemonat). Idempotent. Wird im Entrypoint nach den Migrationen und
 * VOR dem ersten Schreiben aufgerufen, damit Einträge in Monatspartitionen statt
 * in die DEFAULT-Partition fallen.
 *
 * Laufende, vorausschauende Partitionspflege (sowie Archivierung alter
 * Partitionen) übernimmt später der Wartungs-Worker (Step 6).
 */
class AuditPartitionCommand extends Command
{
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $connection = ConnectionManager::get('default');
        $utc = new DateTimeZone('UTC');
        $base = new DateTimeImmutable('first day of this month 00:00:00', $utc);

        $created = 0;
        foreach ([-1, 0, 1] as $offset) {
            $start = $base->modify(sprintf('%+d month', $offset));
            $end = $start->modify('+1 month');
            $name = 'audit_log_' . $start->format('Y_m');

            $exists = $connection->execute(
                'SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
                . "WHERE n.nspname = 'core' AND c.relname = :name",
                ['name' => $name],
            )->fetch();
            if ($exists) {
                continue;
            }

            // Bounds aus kontrollierter Datumsformatierung (kein Injection-Risiko).
            $sql = sprintf(
                "CREATE TABLE core.%s PARTITION OF core.audit_log FOR VALUES FROM ('%s') TO ('%s')",
                $name,
                $start->format('Y-m-d H:i:sP'),
                $end->format('Y-m-d H:i:sP'),
            );
            $connection->execute($sql);
            $io->out("Partition core.$name angelegt.");
            $created++;
        }

        $io->success("Audit-Partitionen sichergestellt ($created neu).");

        return static::CODE_SUCCESS;
    }
}
