<?php
declare(strict_types=1);

namespace App\Service\Privacy;

use App\Service\Registry\ContractRegistry;
use Cake\Database\Connection;
use Throwable;

/**
 * Orchestriert die Modul-Teilnahme an der Benutzer-Anonymisierung (Kap.
 * 27.15.3). Beim irreversiblen Anonymisieren eines Benutzers ruft der Core
 * über den Collector `core.collector.anonymize` jedes registrierte Modul auf,
 * damit es **seine eigenen** personenbezogenen Daten zu dem Benutzer bereinigt.
 *
 * Läuft in der Anonymisierungs-Transaktion (atomar, all-or-nothing): scheitert
 * ein Beitrag, scheitert die gesamte Anonymisierung — besser laut als eine
 * unvollständige Löschung. Für die Dauer der Beiträge wird `app.bypass_rls`
 * gesetzt (privilegierte Core-Operation ohne laufenden Benutzerkontext, damit
 * die Beiträge die Zeilen des Zielnutzers erreichen) und danach wieder auf den
 * vorherigen Wert zurückgesetzt.
 */
class AnonymizationService
{
    public const COLLECTOR = 'core.collector.anonymize';

    public function __construct(private ?ContractRegistry $registry = null)
    {
        $this->registry ??= new ContractRegistry();
    }

    /**
     * @return int Summe der von allen Modulen bereinigten Datensätze.
     */
    public function run(string $userId, Connection $conn): int
    {
        try {
            $classes = $this->registry->collectContributionClasses(self::COLLECTOR);
        } catch (Throwable) {
            // Contract (noch) nicht vorhanden -> keine Modul-Beiträge.
            return 0;
        }
        if ($classes === []) {
            return 0;
        }

        $prev = (string)($conn->execute("SELECT current_setting('app.bypass_rls', true) AS v")->fetch('assoc')['v'] ?? '');
        $conn->execute("SELECT set_config('app.bypass_rls', 'true', true)");
        try {
            $total = 0;
            foreach ($classes as $class) {
                if (!is_string($class) || !class_exists($class)) {
                    continue;
                }
                $impl = new $class();
                if (!$impl instanceof AnonymizeContributorInterface) {
                    continue;
                }
                $total += $impl->anonymizeUser($userId);
            }

            return $total;
        } finally {
            $conn->execute("SELECT set_config('app.bypass_rls', :v, true)", ['v' => $prev === '' ? 'false' : $prev]);
        }
    }
}
