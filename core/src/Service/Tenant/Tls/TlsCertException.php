<?php
declare(strict_types=1);

namespace App\Service\Tenant\Tls;

use RuntimeException;

/**
 * Raised when a TLS certificate bundle is rejected (invalid PEM, host not
 * covered, expired, key mismatch) or cannot be stored.
 */
class TlsCertException extends RuntimeException
{
}
