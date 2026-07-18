<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Permission\AdminGroupService;
use App\Service\Tenant\TenantService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;
use Symfony\Component\Uid\Uuid;

/**
 * Bootstraps the ADMINISTRATOR group of a tenant (ch. 25): creates it when
 * missing and grants it full BREAD + extra-action permissions on every
 * group-capable resource. Idempotent — re-run after installing a module to top
 * up the grants for its newly registered resources.
 *
 * Examples:
 *   bin/cake group_init
 *   bin/cake group_init --tenant localdemo --user demoadmin
 */
class GroupInitCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription(
                'Legt die Administratoren-Gruppe eines Mandanten an (idempotent) und '
                . 'vergibt Vollrechte auf alle gruppenfaehigen Ressourcen.',
            )
            ->addOption('tenant', [
                'help' => 'Mandant (key oder UUID); Standard: der Betreiber-/Default-Mandant',
            ])
            ->addOption('name', [
                'help' => 'Gruppenname',
                'default' => AdminGroupService::DEFAULT_NAME,
            ])
            ->addOption('user', [
                'help' => 'Benutzername, der der Gruppe zusaetzlich hinzugefuegt wird',
            ]);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        $tenantId = $this->resolveTenant((string)($args->getOption('tenant') ?? ''));
        if ($tenantId === null) {
            $io->error('Mandant nicht gefunden.');

            return static::CODE_ERROR;
        }

        // RLS tenant context for the permission rows (their tenant_id defaults
        // to core.current_tenant()); same CLI pattern as the outbox worker.
        $conn->execute("SELECT set_config('app.current_tenant_id', :t, false)", ['t' => $tenantId]);
        try {
            $service = new AdminGroupService();
            $result = $service->ensure($tenantId, (string)$args->getOption('name'));

            $io->out(sprintf(
                '%s "%s" (ID %s), Vollrechte auf %d Ressource(n) gesetzt.',
                $result['created'] ? 'Gruppe angelegt:' : 'Gruppe existiert:',
                (string)$args->getOption('name'),
                $result['id'],
                $result['granted'],
            ));

            $username = trim((string)($args->getOption('user') ?? ''));
            if ($username !== '') {
                $user = $conn->execute(
                    'SELECT id FROM users WHERE lower(username) = lower(:u) '
                    . 'AND coalesce(tenant_id, :d) = :t',
                    ['u' => $username, 'd' => TenantService::DEFAULT_TENANT_ID, 't' => $tenantId],
                )->fetch('assoc');
                if ($user === false) {
                    $io->error("Benutzer \"$username\" im Mandanten nicht gefunden.");

                    return static::CODE_ERROR;
                }
                $service->addUser($result['id'], (string)$user['id']);
                $io->out("Benutzer \"$username\" ist Mitglied der Gruppe.");
            }
        } finally {
            $conn->execute("SELECT set_config('app.current_tenant_id', '', false)");
        }

        $io->success('group_init abgeschlossen.');

        return static::CODE_SUCCESS;
    }

    /** Resolves --tenant (key or UUID) to a tenant id; default tenant when omitted. */
    private function resolveTenant(string $option): ?string
    {
        if ($option === '') {
            return TenantService::DEFAULT_TENANT_ID;
        }
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        if (Uuid::isValid($option)) {
            $row = $conn->execute('SELECT id FROM tenants WHERE id = :id', ['id' => $option])->fetch('assoc');
        } else {
            $row = $conn->execute('SELECT id FROM tenants WHERE key = :k', ['k' => $option])->fetch('assoc');
        }

        return $row === false ? null : (string)$row['id'];
    }
}
