<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Event\OutboxPublisher;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Publiziert ein Event in den Outbox (Admin-/Testhilfe; Module nutzen den
 * OutboxPublisher direkt innerhalb ihrer Transaktion).
 *
 *   bin/cake outbox_publish core.event.ping '{"foo":1}'
 */
class OutboxPublishCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription('Publiziert ein Event in den Outbox.')
            ->addArgument('contract', ['help' => 'Contract-Name', 'required' => true])
            ->addArgument('payload', ['help' => 'JSON-Payload (optional)']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $raw = (string)($args->getArgument('payload') ?? '{}');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $io->error('Payload muss gültiges JSON-Objekt sein.');

            return static::CODE_ERROR;
        }

        $id = (new OutboxPublisher())->publish((string)$args->getArgument('contract'), $payload);
        $io->success("Event publiziert: $id");

        return static::CODE_SUCCESS;
    }
}
