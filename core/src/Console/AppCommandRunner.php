<?php
declare(strict_types=1);

namespace App\Console;

use App\Error\UniqueViolation;
use Cake\Console\CommandInterface;
use Cake\Console\CommandRunner;
use Cake\Console\ConsoleIo;
use Throwable;

/**
 * CLI counterpart to {@see \App\Middleware\UniqueViolationMiddleware}: wraps every
 * dispatched command so a PostgreSQL unique-constraint violation (SQLSTATE 23505)
 * — creating or renaming to an already-existing name/value — is reported as a
 * warning + non-zero exit instead of an unhandled stack trace. Everything else
 * propagates to the normal console exception handler unchanged.
 *
 * runCommand() is the single method EVERY dispatched command (core, plugin,
 * module) passes through, so this covers them all without touching any command.
 * Wired in bin/cake.php in place of the stock CommandRunner.
 */
class AppCommandRunner extends CommandRunner
{
    /**
     * @param \Cake\Console\CommandInterface $command The command to run.
     * @param list<string> $argv The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io.
     * @return int|null The exit code, or null.
     */
    protected function runCommand(CommandInterface $command, array $argv, ConsoleIo $io): ?int
    {
        try {
            return parent::runCommand($command, $argv, $io);
        } catch (Throwable $e) {
            $constraint = UniqueViolation::constraintOf($e);
            if ($constraint === null) {
                throw $e;
            }
            $io->warning(UniqueViolation::messageFor($constraint));

            return CommandInterface::CODE_ERROR;
        }
    }
}
