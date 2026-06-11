<?php
declare(strict_types=1);

namespace App\Service\Auth\Sso;

use RuntimeException;

/**
 * Error in the SSO flow (P06): discovery/token exchange/signature/claim checks.
 */
class SsoException extends RuntimeException
{
}
