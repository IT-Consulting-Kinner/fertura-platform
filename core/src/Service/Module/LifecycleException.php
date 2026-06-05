<?php
declare(strict_types=1);

namespace App\Service\Module;

use RuntimeException;

/**
 * Fehler in einer Lifecycle-Operation (Validierung, Konflikt, belegter Lock).
 */
class LifecycleException extends RuntimeException
{
}
