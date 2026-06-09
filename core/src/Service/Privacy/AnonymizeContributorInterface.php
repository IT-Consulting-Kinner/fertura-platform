<?php
declare(strict_types=1);

namespace App\Service\Privacy;

/**
 * Beitrag eines Moduls zur Benutzer-Anonymisierung (Collector
 * `core.collector.anonymize`, Kap. 27.15.3).
 *
 * Wird vom Core aufgerufen, wenn ein Benutzer **irreversibel anonymisiert**
 * wird. Das Modul bereinigt **seine eigenen** personenbezogenen Daten (z. B.
 * Freitextfelder in Modultabellen) zu diesem Benutzer — der Core kennt die
 * Modul-Datenmodelle nicht. Technische IDs/Referenzen sollen erhalten bleiben
 * (wie im Core), nur personenbezogene Inhalte werden ersetzt/entfernt.
 *
 * Läuft in der Anonymisierungs-Transaktion des Core (atomar). Out-of-Process-
 * Module führen ihren Beitrag im isolierten Host aus (Kap. 23.16.2).
 */
interface AnonymizeContributorInterface
{
    /**
     * Bereinigt die personenbezogenen Daten des Benutzers `$userId` (UUID).
     *
     * @return int Anzahl bereinigter Datensätze (für Protokoll/Audit).
     */
    public function anonymizeUser(string $userId): int;
}
