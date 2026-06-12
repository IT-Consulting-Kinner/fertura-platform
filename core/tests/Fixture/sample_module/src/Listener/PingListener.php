<?php
declare(strict_types=1);

namespace SampleModule\Listener;

use App\Event\EventListenerInterface;
use Cake\Datasource\ConnectionManager;
use stdClass;

/**
 * Example listener of the sample module: logs every ping event into the module's
 * own table mod_sample_module.ping_log. Demonstrates real loading of module code
 * (PSR-4) and the Outbox->Registry->Listener path.
 */
class PingListener implements EventListenerInterface
{
    public function handle(array $payload, array $context): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO mod_sample_module.ping_log (payload) VALUES (CAST(:p AS jsonb))',
            ['p' => json_encode($payload === [] ? new stdClass() : $payload)],
        );
    }
}
