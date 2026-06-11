<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Auth\AuthProviderResolver;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Authentication status: shows the registered provider and the one effectively
 * active for the resolver slot `core.auth.provider` (ch. 27.2.2).
 *
 *   bin/cake auth status
 */
class AuthCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription('Status der Authentifizierungsmethode (Provider-Resolver-Slot).')
            ->addArgument('operation', ['help' => 'status', 'choices' => ['status'], 'default' => 'status']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $resolver = new AuthProviderResolver();
        $registered = $resolver->resolveClass();
        $effective = $resolver->provider();

        $io->out('Registrierter Provider: ' . ($registered ?? '(keiner — lokaler Default)'));
        $io->out('Effektiv aktiv:         ' . $effective::class . '  [' . $effective->label() . ']');

        if ($registered !== null && $effective::class !== $registered) {
            $io->warning('Registrierter Provider ist nicht nutzbar → lokaler Fallback (Break-Glass).');
        }

        return static::CODE_SUCCESS;
    }
}
