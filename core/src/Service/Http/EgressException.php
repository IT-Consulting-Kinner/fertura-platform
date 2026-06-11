<?php
declare(strict_types=1);

namespace App\Service\Http;

use RuntimeException;

/**
 * Error during hardened outbound-HTTP access (ch. 20 / 23.16): policy violation
 * (SSRF/allowlist/scheme), size overrun, or transport failure.
 */
class EgressException extends RuntimeException
{
}
