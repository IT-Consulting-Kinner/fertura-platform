<?php
declare(strict_types=1);

namespace App\Command;

use App\Event\Demo\FailingListener;
use App\Event\Demo\RecordingListener;
use App\Model\Entity\Contract;
use App\Model\Entity\ContractRegistration;
use App\Service\Event\OutboxPublisher;
use App\Service\Registry\ContractRegistry;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Datasource\ConnectionManager;

/**
 * Integrationstest des Outbox-Workers (Step 6): registriert Event-Contracts +
 * ladbare Demo-Listener, publiziert Events und wartet auf den LAUFENDEN
 * worker-Container (realistische End-to-End-Prüfung). Verifiziert Erfolg,
 * Retry→Dead-Letter und Listener-Isolation; räumt anschließend auf.
 */
class OutboxSelftestCommand extends Command
{
    private int $failures = 0;

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $connection = ConnectionManager::get('default');
        $registry = new ContractRegistry();
        $publisher = new OutboxPublisher();

        $this->cleanup();
        $connection->execute(
            'CREATE TABLE IF NOT EXISTS core.outbox_selftest_log ('
            . 'id uuid NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY, '
            . 'contract_name text, event_id text, created_at timestamptz NOT NULL DEFAULT now())',
        );

        $rec = RecordingListener::class;
        $fail = FailingListener::class;

        $registry->registerContract('selftest6', 'selftest6.event.ok', Contract::TYPE_EVENT, '1.0.0');
        $registry->register('modR', 'selftest6.event.ok', ContractRegistration::TYPE_LISTENER, ['implementationClass' => $rec]);

        $registry->registerContract('selftest6', 'selftest6.event.fail', Contract::TYPE_EVENT, '1.0.0');
        $registry->register('modF', 'selftest6.event.fail', ContractRegistration::TYPE_LISTENER, ['implementationClass' => $fail]);

        $registry->registerContract('selftest6', 'selftest6.event.mixed', Contract::TYPE_EVENT, '1.0.0');
        $registry->register('modR2', 'selftest6.event.mixed', ContractRegistration::TYPE_LISTENER, ['implementationClass' => $rec, 'priority' => 10]);
        $registry->register('modF2', 'selftest6.event.mixed', ContractRegistration::TYPE_LISTENER, ['implementationClass' => $fail, 'priority' => 5]);

        // fail/mixed mit max_attempts=2 für schnellen Dead-Letter.
        $publisher->publish('selftest6.event.ok', ['x' => 1]);
        $publisher->publish('selftest6.event.fail', ['x' => 2], ['maxAttempts' => 2]);
        $publisher->publish('selftest6.event.mixed', ['x' => 3], ['maxAttempts' => 2]);

        $io->out('Warte auf Verarbeitung durch den worker-Container ...');
        $deadline = time() + 40;
        do {
            usleep(500_000);
            $ok = $this->status('selftest6.event.ok');
            $failS = $this->status('selftest6.event.fail');
            $mixedS = $this->status('selftest6.event.mixed');
            $ready = $ok === 'done' && $failS === 'dead_letter' && $mixedS === 'dead_letter';
        } while (!$ready && time() < $deadline);

        $this->assert($io, $ok === 'done', "ok-Event -> done (ist: $ok)");
        $this->assert($io, $this->recordCount('selftest6.event.ok') >= 1, 'ok-Listener ausgefuehrt');

        $failRow = $this->row('selftest6.event.fail');
        $this->assert($io, ($failRow['status'] ?? null) === 'dead_letter', 'fail-Event -> dead_letter (ist: ' . ($failRow['status'] ?? '?') . ')');
        $this->assert($io, (int)($failRow['attempt_count'] ?? 0) === 2, 'fail-Event: attempt_count = max (2)');
        $this->assert($io, !empty($failRow['last_error']), 'fail-Event: last_error gesetzt');

        $this->assert($io, $mixedS === 'dead_letter', "mixed-Event -> dead_letter (ist: $mixedS)");
        $this->assert($io, $this->recordCount('selftest6.event.mixed') >= 2, 'Isolation: Recording-Listener je Versuch trotz Co-Listener-Fehler');

        $this->cleanup();

        if ($this->failures > 0) {
            $io->error("Outbox-Selbsttest FEHLGESCHLAGEN ({$this->failures}).");

            return static::CODE_ERROR;
        }
        $io->success('Outbox-Selbsttest bestanden.');

        return static::CODE_SUCCESS;
    }

    private function assert(ConsoleIo $io, bool $cond, string $label): void
    {
        $io->out(($cond ? '  <success>OK</success>   ' : '  <error>FAIL</error> ') . $label);
        if (!$cond) {
            $this->failures++;
        }
    }

    private function status(string $contract): ?string
    {
        return $this->row($contract)['status'] ?? null;
    }

    /** @return array<string, mixed> */
    private function row(string $contract): array
    {
        $row = ConnectionManager::get('default')->execute(
            'SELECT status, attempt_count, last_error FROM event_outbox '
            . 'WHERE contract_name = :c ORDER BY created_at DESC LIMIT 1',
            ['c' => $contract],
        )->fetch('assoc');

        return $row ?: [];
    }

    private function recordCount(string $contract): int
    {
        $row = ConnectionManager::get('default')->execute(
            'SELECT count(*) AS c FROM outbox_selftest_log WHERE contract_name = :c',
            ['c' => $contract],
        )->fetch('assoc');

        return (int)($row['c'] ?? 0);
    }

    private function cleanup(): void
    {
        $connection = ConnectionManager::get('default');
        $connection->execute("DELETE FROM event_outbox WHERE contract_name LIKE 'selftest6.%'");
        $connection->execute("DELETE FROM contracts WHERE owner_module_key = 'selftest6'");
        $connection->execute('DROP TABLE IF EXISTS core.outbox_selftest_log');
    }
}
