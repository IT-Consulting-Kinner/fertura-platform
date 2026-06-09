<?php
declare(strict_types=1);

namespace App\Service\Auth\Sso;

use RuntimeException;

/**
 * Fehler im SSO-Flow (P06): Discovery/Token-Austausch/Signatur-/Claim-Prüfung.
 */
class SsoException extends RuntimeException
{
}
