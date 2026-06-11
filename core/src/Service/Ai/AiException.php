<?php
declare(strict_types=1);

namespace App\Service\Ai;

use RuntimeException;

/** Error in the AI gateway (P11): not configured, provider failure, or unsupported. */
class AiException extends RuntimeException
{
}
