<?php
declare(strict_types=1);

namespace App\Service\Registry;

/**
 * Contract for implementing a public module interface
 * (service contract, ch. 29.3 / 26.3.4).
 *
 * The providing main module supplies the implementation; consuming modules
 * invoke it exclusively through a {@see CapabilityHandle} (ch. 29.8.3). Input and
 * output are machine-readable associative structures conforming to the contract's
 * input/output specification (ch. 29.6). The business semantics are defined
 * solely by the providing module (ch. 29.6.3).
 */
interface ServiceInterface
{
    /**
     * Handles an interface call.
     *
     * @param array<string, mixed> $input Input per the input specification.
     * @return array<string, mixed> Response per the output specification.
     */
    public function handle(array $input): array;
}
