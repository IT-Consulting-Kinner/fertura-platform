<?php
declare(strict_types=1);

namespace IsolatedAnon;

use App\Service\Registry\ServiceInterface;

/**
 * Data resolver (input -> output) of the isolated module. Invoked like a service
 * via `handle()` and runs (out_of_process) in the isolated host over RPC
 * (ch. 23.16.2 / 26).
 */
class EchoResolver implements ServiceInterface
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function handle(array $input): array
    {
        return ['resolved' => (string)($input['q'] ?? '')];
    }
}
