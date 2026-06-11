<?php
declare(strict_types=1);

namespace App\Service\Registry;

use RuntimeException;

/**
 * Error during contract registration/validation (e.g. unknown contract,
 * incompatible version, occupied resolver slot).
 */
class RegistryException extends RuntimeException
{
}
