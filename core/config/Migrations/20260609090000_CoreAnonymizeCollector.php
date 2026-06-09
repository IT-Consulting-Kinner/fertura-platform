<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Core-Collector-Contract für die Anonymisierung (Kap. 27.15.3): Beim
 * irreversiblen Anonymisieren eines Benutzers benachrichtigt der Core über
 * diesen Collector die Module, damit jedes Modul **seine eigenen** zu dem
 * Benutzer gehörenden personenbezogenen (Freitext-)Daten bereinigt — der Core
 * kennt die Modul-Datenmodelle nicht. Beiträge implementieren
 * App\Service\Privacy\AnonymizeContributorInterface.
 */
class CoreAnonymizeCollector extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            INSERT INTO core.contracts
                (owner_module_key, name, contract_type, version, multi_use, active, description)
            VALUES
                ('core', 'core.collector.anonymize', 'collector', '1.0.0', true, true,
                 'Anonymisierung (Kap. 27.15.3): Module bereinigen ihre eigenen personenbezogenen Daten zu einem Benutzer. Implementierungen liefern App\Service\Privacy\AnonymizeContributorInterface.')
            ON CONFLICT (name) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        $this->execute("DELETE FROM core.contracts WHERE name = 'core.collector.anonymize'");
    }
}
