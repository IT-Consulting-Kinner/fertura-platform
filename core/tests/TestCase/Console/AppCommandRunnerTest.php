<?php
declare(strict_types=1);

namespace App\Test\TestCase\Console;

use App\Application;
use App\Console\AppCommandRunner;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\CommandInterface;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Database\Exception\QueryException;
use Cake\I18n\I18n;
use Cake\TestSuite\TestCase;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Unit test for the global CLI safety net: a 23505 raised by any command becomes
 * a warning + non-zero exit; anything else propagates unchanged.
 */
class AppCommandRunnerTest extends TestCase
{
    private string $locale = 'en_US';

    protected function setUp(): void
    {
        parent::setUp();
        $this->locale = I18n::getLocale();
        I18n::setLocale('de_DE');
    }

    protected function tearDown(): void
    {
        I18n::setLocale($this->locale);
        parent::tearDown();
    }

    /** AppCommandRunner with the protected runCommand exposed for direct testing. */
    private function runner(): AppCommandRunner
    {
        return new class (new Application(CONFIG), 'cake') extends AppCommandRunner {
            public function call(CommandInterface $command, array $argv, ConsoleIo $io): ?int
            {
                return $this->runCommand($command, $argv, $io);
            }
        };
    }

    private function throwingCommand(Throwable $e): Command
    {
        return new class ($e) extends Command {
            public function __construct(private Throwable $err)
            {
                parent::__construct();
            }

            public function execute(Arguments $args, ConsoleIo $io): int
            {
                throw $this->err;
            }
        };
    }

    private function violation(): QueryException
    {
        $msg = 'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "uq_tenants_key"';
        $pdo = new PDOException($msg);
        $pdo->errorInfo = ['23505', 7, $msg];

        return new QueryException('INSERT ...', $pdo);
    }

    public function testUniqueViolationBecomesWarningAndErrorCode(): void
    {
        $out = new StubConsoleOutput();
        $io = new ConsoleIo($out, $out);
        $code = $this->runner()->call($this->throwingCommand($this->violation()), ['dummy'], $io);

        $this->assertSame(CommandInterface::CODE_ERROR, $code);
        $this->assertStringContainsString('bereits vergeben', implode("\n", $out->messages()));
    }

    public function testNonUniqueErrorPropagates(): void
    {
        $out = new StubConsoleOutput();
        $io = new ConsoleIo($out, $out);
        $this->expectException(RuntimeException::class);
        $this->runner()->call($this->throwingCommand(new RuntimeException('boom')), ['dummy'], $io);
    }
}
