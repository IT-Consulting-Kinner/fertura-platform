<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Webhook\WebhookService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Verwaltung der Outbound-Webhooks (Programm Tier-1, P05) auf der Konsole.
 *
 *   bin/cake webhook list
 *   bin/cake webhook add --name X --url https://… [--events core.user.created] [--secret s]
 *   bin/cake webhook on|off|rm <id>
 *   bin/cake webhook deliver
 *   bin/cake webhook deliveries [pending|done|dead_letter]
 *   bin/cake webhook retry <id>
 */
class WebhookCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('Outbound-Webhooks verwalten (P05).');
        $parser->addArgument('action', [
            'help' => 'list|add|rm|on|off|deliver|deliveries|retry',
            'required' => true,
        ]);
        $parser->addArgument('id', ['help' => 'Subscription-/Delivery-ID bzw. Status (deliveries)', 'required' => false]);
        $parser->addOption('name', ['help' => 'Anzeigename (add)']);
        $parser->addOption('url', ['help' => 'Ziel-URL (add)']);
        $parser->addOption('events', ['help' => 'Event-Filter: * oder kommagetrennt', 'default' => '*']);
        $parser->addOption('secret', ['help' => 'HMAC-Geheimnis (add)']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $svc = new WebhookService();
        $action = (string)$args->getArgument('action');
        $id = (string)$args->getArgument('id');

        switch ($action) {
            case 'list':
                foreach ($svc->listSubscriptions() as $s) {
                    $io->out(sprintf(
                        '%s  [%s]  %s  events=%s  secret=%s  %s',
                        $s['id'],
                        $s['active'] ? 'aktiv' : 'inaktiv',
                        $s['name'],
                        $s['event_filter'],
                        $s['has_secret'] ? 'ja' : 'nein',
                        $s['url'],
                    ));
                }

                return self::CODE_SUCCESS;

            case 'add':
                $name = (string)$args->getOption('name');
                $url = (string)$args->getOption('url');
                if ($name === '' || $url === '') {
                    $io->error('--name und --url sind erforderlich.');

                    return self::CODE_ERROR;
                }
                $res = $svc->createSubscription(
                    $name,
                    $url,
                    (string)$args->getOption('events'),
                    $args->getOption('secret') !== null ? (string)$args->getOption('secret') : null,
                );
                $io->success('Subscription angelegt: ' . $res['id']);

                return self::CODE_SUCCESS;

            case 'on':
            case 'off':
                if ($id === '') {
                    $io->error('ID erforderlich.');

                    return self::CODE_ERROR;
                }
                $svc->setActive($id, $action === 'on');
                $io->success('OK.');

                return self::CODE_SUCCESS;

            case 'rm':
                if ($id === '') {
                    $io->error('ID erforderlich.');

                    return self::CODE_ERROR;
                }
                $svc->deleteSubscription($id);
                $io->success('Gelöscht.');

                return self::CODE_SUCCESS;

            case 'deliver':
                $n = $svc->deliverPending();
                $io->out("Zugestellt/bearbeitet: $n");

                return self::CODE_SUCCESS;

            case 'deliveries':
                $status = $id !== '' ? $id : null;
                foreach ($svc->listDeliveries($status, 50) as $d) {
                    $io->out(sprintf(
                        '%s  %-11s  %s  attempts=%d  code=%s  %s',
                        $d['id'],
                        $d['status'],
                        $d['event_name'],
                        (int)$d['attempt_count'],
                        $d['last_status_code'] ?? '-',
                        $d['last_error'] ?? '',
                    ));
                }

                return self::CODE_SUCCESS;

            case 'retry':
                if ($id === '') {
                    $io->error('ID erforderlich.');

                    return self::CODE_ERROR;
                }
                $svc->retryDelivery($id);
                $io->success('Erneut eingereiht.');

                return self::CODE_SUCCESS;

            default:
                $io->error("Unbekannte Aktion: $action");

                return self::CODE_ERROR;
        }
    }
}
