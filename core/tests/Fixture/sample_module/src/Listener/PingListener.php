<?php
declare(strict_types=1);

namespace SampleModule\Listener;

use App\Event\EventListenerInterface;
use Cake\Datasource\ConnectionManager;

/**
 * Beispiel-Listener des Sample-Moduls: protokolliert jedes Ping-Event in der
 * modul-eigenen Tabelle mod_sample_module.ping_log. Demonstriert echtes Laden
 * von Modul-Code (PSR-4) und den Outbox→Registry→Listener-Pfad.
 */
class PingListener implements EventListenerInterface
{
    public function handle(array $payload, array $context): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO mod_sample_module.ping_log (payload) VALUES (CAST(:p AS jsonb))',
            ['p' => json_encode($payload === [] ? new \stdClass() : $payload)],
        );
    }
}
