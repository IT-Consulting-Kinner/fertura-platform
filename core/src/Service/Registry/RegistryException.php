<?php
declare(strict_types=1);

namespace App\Service\Registry;

use RuntimeException;

/**
 * Fehler bei Contract-Registrierung/-Validierung (z. B. unbekannter Contract,
 * inkompatible Version, belegter Resolver-Slot).
 */
class RegistryException extends RuntimeException
{
}
