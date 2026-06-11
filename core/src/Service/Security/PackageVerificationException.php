<?php
declare(strict_types=1);

namespace App\Service\Security;

use RuntimeException;

/**
 * Signature/trust error raised during package verification (ch. 24.9).
 */
class PackageVerificationException extends RuntimeException
{
}
