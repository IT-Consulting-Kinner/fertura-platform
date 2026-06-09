<?php
declare(strict_types=1);

namespace IsolatedAnon;

use App\Service\Registry\ServiceInterface;

/**
 * Daten-Resolver (input -> output) des isolierten Moduls. Wird wie ein Service
 * über `handle()` aufgerufen und läuft (out_of_process) im isolierten Host
 * über RPC (Kap. 23.16.2 / 26).
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
