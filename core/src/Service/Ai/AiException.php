<?php
declare(strict_types=1);

namespace App\Service\Ai;

use RuntimeException;

/** Fehler im AI-Gateway (P11): nicht konfiguriert, Provider-Fehler, nicht unterstützt. */
class AiException extends RuntimeException
{
}
