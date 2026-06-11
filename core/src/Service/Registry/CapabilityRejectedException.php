<?php
declare(strict_types=1);

namespace App\Service\Registry;

use RuntimeException;

/**
 * Technical rejection of an interface call (ch. 29.8.4).
 *
 * Thrown when a module invokes a public module interface without a valid
 * capability handle (no handle, binding revoked, providing module
 * deactivated/incompatible, no active provider). The platform thereby
 * *technically* rejects the unregistered call; how this is handled at the
 * *business* level (default, empty result, hiding, error, abort) is decided by
 * the calling module.
 */
class CapabilityRejectedException extends RuntimeException
{
}
