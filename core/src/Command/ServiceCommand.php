<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Registry\CapabilityRejectedException;
use App\Service\Registry\ContractRegistry;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;

/**
 * Inspect and invoke public module interfaces (service contracts, ch. 29).
 *
 *   bin/cake service list
 *   bin/cake service call <nutzendes_modul> <interface> '<json-input>'
 *
 * `call` invokes the interface exclusively through the consuming module's
 * capability handle (ch. 29.8.3); without a valid binding the rejection
 * behaviour applies (ch. 29.8.4).
 */
class ServiceCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription('Öffentliche Modul-Interfaces (Service-Contracts) anzeigen/aufrufen.')
            ->addArgument('operation', [
                'help' => 'list|call',
                'choices' => ['list', 'call'],
                'required' => true,
            ])
            ->addArgument('module', ['help' => 'Nutzendes Modul (bei call)'])
            ->addArgument('interface', ['help' => 'Interface-/Contract-Name (bei call)'])
            ->addArgument('input', ['help' => 'JSON-Eingabe (bei call)']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        return match ($args->getArgument('operation')) {
            'list' => $this->list($io),
            'call' => $this->call($args, $io),
            default => static::CODE_ERROR,
        };
    }

    private function list(ConsoleIo $io): int
    {
        $rows = ConnectionManager::get('default')->execute(
            "SELECT c.name, c.version, c.owner_module_key, c.multi_use, c.active, "
            . "(SELECT module_key FROM contract_registrations r WHERE r.contract_id = c.id "
            . "AND r.registration_type = 'provider' AND r.active LIMIT 1) AS provider, "
            . "(SELECT count(*) FROM contract_registrations r WHERE r.contract_id = c.id "
            . "AND r.registration_type = 'service_consumer' AND r.active) AS consumers "
            . "FROM contracts c WHERE c.contract_type = 'service' ORDER BY c.name",
        )->fetchAll('assoc');

        if ($rows === []) {
            $io->out('<info>Keine öffentlichen Modul-Interfaces registriert.</info>');

            return static::CODE_SUCCESS;
        }
        foreach ($rows as $r) {
            $io->out(sprintf(
                '%s v%s  Anbieter=%s  Provider-aktiv=%s  multi_use=%s  Nutzer=%d',
                $r['name'],
                $r['version'],
                $r['owner_module_key'],
                $r['provider'] ?? '(keiner)',
                $this->b($r['multi_use']) ? 'ja' : 'nein',
                (int)$r['consumers'],
            ));
        }

        return static::CODE_SUCCESS;
    }

    private function call(Arguments $args, ConsoleIo $io): int
    {
        $module = (string)$args->getArgument('module');
        $interface = (string)$args->getArgument('interface');
        $rawInput = $args->getArgument('input') ?? '{}';
        if ($module === '' || $interface === '') {
            $io->error('call benötigt <modul> <interface> [json-input].');

            return static::CODE_ERROR;
        }
        $input = json_decode((string)$rawInput, true);
        if (!is_array($input)) {
            $io->error('Eingabe ist kein gültiges JSON-Objekt.');

            return static::CODE_ERROR;
        }

        $registry = new ContractRegistry();
        $handle = $registry->handleFor($module, $interface);
        if ($handle === null) {
            $io->error("Kein gültiges Handle: Modul '$module' hat keine aktive Bindung an '$interface'.");

            return static::CODE_ERROR;
        }

        try {
            $output = $handle->invoke($input);
        } catch (CapabilityRejectedException $e) {
            // Technical rejection (ch. 29.8.4) - reported here as an error.
            $io->error('Abgewiesen: ' . $e->getMessage());

            return static::CODE_ERROR;
        }

        $io->out((string)json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return static::CODE_SUCCESS;
    }

    private function b(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === '1' || $v === 1;
    }
}
