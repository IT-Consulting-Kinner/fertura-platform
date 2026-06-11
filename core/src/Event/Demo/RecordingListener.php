<?php
declare(strict_types=1);

namespace App\Event\Demo;

use App\Event\EventListenerInterface;
use Cake\Datasource\ConnectionManager;

/**
 * Demo/test listener: records every invocation in core.outbox_selftest_log
 * (observable across processes, unlike a static counter). Used only by the
 * outbox self-test.
 */
class RecordingListener implements EventListenerInterface
{
    public function handle(array $payload, array $context): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO outbox_selftest_log (contract_name, event_id) VALUES (:c, :e)',
            ['c' => (string)$context['contract_name'], 'e' => (string)$context['event_id']],
        );
    }
}
