<?php
declare(strict_types=1);

namespace IsolatedModule\Service;

use App\Service\Registry\ServiceInterface;

/**
 * Service-only provider for the out-of-process isolation test. Runs in the
 * isolated module host process (ch. 23.16.2).
 */
class EchoService implements ServiceInterface
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function handle(array $input): array
    {
        $msg = (string)($input['msg'] ?? '');

        return ['echo' => $msg, 'length' => strlen($msg)];
    }
}
