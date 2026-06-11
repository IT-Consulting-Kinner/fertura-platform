<?php
declare(strict_types=1);

namespace App\Service\Storage;

use RuntimeException;

/**
 * Object storage error (program Tier-2, P03): read/write/delete failures of the
 * underlying Flysystem layer, translated into a core exception.
 */
class StorageException extends RuntimeException
{
}
