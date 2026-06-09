<?php
declare(strict_types=1);

namespace App\Service\Http;

use RuntimeException;

/**
 * Fehler beim gehärteten Outbound-HTTP-Zugriff (Kap. 20 / 23.16): Policy-Verstoß
 * (SSRF/Allowlist/Schema), Größenüberschreitung oder Transportfehler.
 */
class EgressException extends RuntimeException
{
}
