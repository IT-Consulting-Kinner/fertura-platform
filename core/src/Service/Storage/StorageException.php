<?php
declare(strict_types=1);

namespace App\Service\Storage;

use RuntimeException;

/**
 * Fehler im Objekt-Storage (Programm Tier-2, P03): Lese-/Schreib-/Lösch-Fehler
 * der zugrunde liegenden Flysystem-Schicht, in eine Core-Ausnahme übersetzt.
 */
class StorageException extends RuntimeException
{
}
