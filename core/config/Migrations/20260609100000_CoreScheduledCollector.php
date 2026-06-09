<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Core-Collector-Contract für periodische Aufgaben (Kap. 20.3/20.4). Module
 * docken über diesen Collector periodische Aufgaben an
 * (`App\Service\Schedule\ScheduledTaskInterface`), die der Core-Worker tickt.
 *
 * Hinweis: Zuvor fehlte der Seed dieses Contracts — Module konnten ihre
 * Scheduled-Tasks daher nicht registrieren (nur die Core-eigenen Aufgaben
 * liefen). Diese Migration schließt die Lücke.
 */
class CoreScheduledCollector extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            INSERT INTO core.contracts
                (owner_module_key, name, contract_type, version, multi_use, active, description)
            VALUES
                ('core', 'core.collector.scheduled', 'collector', '1.0.0', true, true,
                 'Periodische Aufgaben der Module (Kap. 20.3/20.4). Implementierungen liefern App\Service\Schedule\ScheduledTaskInterface.')
            ON CONFLICT (name) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        $this->execute("DELETE FROM core.contracts WHERE name = 'core.collector.scheduled'");
    }
}
