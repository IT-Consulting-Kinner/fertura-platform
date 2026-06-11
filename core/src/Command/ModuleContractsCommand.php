<?php
declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Datasource\ConnectionManager;

/**
 * Lists the core extension points (contracts) plus the interface expected for
 * each (Programme Tier-3, P16) — the "SDK catalogue" for module developers.
 *
 *   bin/cake module_contracts
 */
class ModuleContractsCommand extends Command
{
    /** Known core contracts → the interface to implement. */
    private const INTERFACES = [
        'core.collector.health' => 'App\\Service\\Health\\HealthCheckInterface',
        'core.collector.scheduled' => 'App\\Service\\Schedule\\ScheduledTaskInterface',
        'core.collector.anonymize' => 'App\\Service\\Privacy\\AnonymizeContributorInterface',
        'core.collector.search' => 'App\\Service\\Search\\SearchIndexerInterface',
        'core.collector.notification_channel' => 'App\\Service\\Notification\\NotificationChannelInterface',
        'core.api.route' => 'App\\Service\\Api\\ApiEndpointInterface',
        'core.ai.complete' => '(Core-Service App\\Service\\Ai\\AiGateway)',
        'core.ai.embed' => '(Core-Service App\\Service\\Ai\\AiGateway)',
        'core.auth.provider' => 'App\\Service\\Auth\\AuthProviderInterface (nur in-process)',
    ];

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $io->out('<info>Core-Erweiterungspunkte (Contracts):</info>');
        $rows = ConnectionManager::get('default')->execute(
            "SELECT name, contract_type, description FROM contracts WHERE owner_module_key = 'core' ORDER BY name",
        )->fetchAll('assoc');

        foreach ($rows as $r) {
            $interface = self::INTERFACES[$r['name']] ?? '—';
            $io->out(sprintf('  %-38s [%s]', $r['name'], $r['contract_type']));
            $io->out(sprintf('      Interface: %s', $interface));
            if (!empty($r['description'])) {
                $io->out('      ' . $r['description']);
            }
        }
        $io->out('');
        $io->out('Manifest-Sektionen: collectors_registered / resolvers_registered / '
            . 'services_registered / events_registered / api_routes / contracts_provided.');

        return self::CODE_SUCCESS;
    }
}
