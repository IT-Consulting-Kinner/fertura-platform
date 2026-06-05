<?php
declare(strict_types=1);

namespace SampleModule\Service;

use App\Service\Registry\ServiceInterface;

/**
 * Implementierung des öffentlichen Modul-Interfaces sample_module.service.echo
 * (Service-Contract, Kap. 29). Gibt die Eingabe strukturiert zurück.
 */
class EchoService implements ServiceInterface
{
    public function handle(array $input): array
    {
        $msg = (string)($input['msg'] ?? '');

        return ['echo' => $msg, 'length' => strlen($msg)];
    }
}
