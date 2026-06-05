<?php
declare(strict_types=1);

namespace App\Service\Security;

use RuntimeException;

/**
 * Signatur-/Vertrauensfehler bei der Paketprüfung (Kap. 24.9).
 */
class PackageVerificationException extends RuntimeException
{
}
