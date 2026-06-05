<?php
declare(strict_types=1);

namespace App\Service\Registry;

use RuntimeException;

/**
 * Technische Abweisung eines Interface-Aufrufs (Kap. 29.8.4).
 *
 * Wird geworfen, wenn ein Modul ein öffentliches Modul-Interface ohne gültiges
 * Capability-Handle aufruft (kein Handle, Bindung widerrufen, anbietendes Modul
 * deaktiviert/inkompatibel, kein aktiver Provider). Die Plattform lehnt den
 * nicht registrierten Aufruf damit *technisch* ab; wie das *fachlich* verarbeitet
 * wird (Default, leeres Ergebnis, Ausblenden, Fehler, Abbruch), entscheidet das
 * aufrufende Modul.
 */
class CapabilityRejectedException extends RuntimeException
{
}
