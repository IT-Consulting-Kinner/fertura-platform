<?php
declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Stellt monatliche Partitionen für die zeitbereichs-partitionierten Tabellen
 * (core.audit_log, core.event_outbox) sicher: Vormonat, aktueller Monat,
 * Folgemonat. Idempotent. Wird im Entrypoint nach den Migrationen und VOR dem
 * ersten Schreiben aufgerufen, damit Einträge in Monatspartitionen statt in die
 * DEFAULT-Partition fallen.
 *
 * Laufende, vorausschauende Partitionspflege + Archivierung alter Partitionen
 * = Wartungs-Worker/Operations (später).
 */
class AuditPartitionCommand extends Command
{
    private const PARTITIONED_TABLES = ['audit_log', 'event_outbox'];

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');
        $utc = new DateTimeZone('UTC');
        $base = new DateTimeImmutable('first day of this month 00:00:00', $utc);

        $created = 0;
        foreach (self::PARTITIONED_TABLES as $table) {
            if (!$this->relationExists($connection, $table)) {
                continue;
            }
            foreach ([-1, 0, 1] as $offset) {
                $start = $base->modify(sprintf('%+d month', $offset));
                $end = $start->modify('+1 month');
                $name = $table . '_' . $start->format('Y_m');

                if ($this->relationExists($connection, $name)) {
                    continue;
                }

                // Bounds aus kontrollierter Datumsformatierung (kein Injection-Risiko).
                $sql = sprintf(
                    "CREATE TABLE core.%s PARTITION OF core.%s FOR VALUES FROM ('%s') TO ('%s')",
                    $name,
                    $table,
                    $start->format('Y-m-d H:i:sP'),
                    $end->format('Y-m-d H:i:sP'),
                );
                $connection->execute($sql);
                $io->out("Partition core.$name angelegt.");
                $created++;
            }
        }

        $io->success("Partitionen sichergestellt ($created neu).");

        return static::CODE_SUCCESS;
    }

    private function relationExists(Connection $connection, string $name): bool
    {
        return $connection->execute(
            'SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . "WHERE n.nspname = 'core' AND c.relname = :name",
            ['name' => $name],
        )->fetch() !== false;
    }
}
