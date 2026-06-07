<?php
declare(strict_types=1);

namespace App\Service\Schedule;

/**
 * Periodische Modul-Aufgabe (Andock-Punkt, Kap. 20.3 / 20.4).
 *
 * Module (z. B. Ticketing: `fetch_mails`, `check_escalations`) registrieren
 * Implementierungen über `collectors_registered` für den Collector
 * `core.collector.scheduled` im Manifest. Der Core-Worker tickt sie im
 * konfigurierten Intervall (fehlerisoliert, mit Heartbeat). Die fachliche Logik
 * bleibt im Modul.
 */
interface ScheduledTaskInterface
{
    /** Eindeutiger, stabiler Schlüssel (z. B. `ticketing.fetch_mails`). */
    public function key(): string;

    /** Mindestabstand zwischen zwei Läufen in Sekunden. */
    public function intervalSeconds(): int;

    /** Führt die Aufgabe aus. Ausnahmen werden vom Runner isoliert/protokolliert. */
    public function run(): void;
}
