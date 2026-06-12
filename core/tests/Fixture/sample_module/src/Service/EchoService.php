<?php
declare(strict_types=1);

namespace SampleModule\Service;

use App\Service\Registry\ServiceInterface;

/**
 * Implementation of the public module interface sample_module.service.echo
 * (service contract, ch. 29). Returns the input in structured form.
 */
class EchoService implements ServiceInterface
{
    public function handle(array $input): array
    {
        $msg = (string)($input['msg'] ?? '');

        return ['echo' => $msg, 'length' => strlen($msg)];
    }
}
