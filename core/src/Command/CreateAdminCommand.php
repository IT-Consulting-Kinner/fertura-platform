<?php
declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditLogger;
use App\Auth\PasswordPolicy;
use App\Model\Entity\User;
use App\Service\Permission\AdminGroupService;
use App\Service\Tenant\TenantService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Symfony\Component\Uid\Uuid;

/**
 * Creates a full administrator (or updates one) and assigns them every core
 * administration area. Makes the core usable without any additional module.
 *
 * Example:
 *   bin/cake create_admin admin admin@example.com 'GeheimesPasswort1!'
 */
class CreateAdminCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription('Legt einen Volladministrator an und weist alle Administrationsbereiche zu.')
            ->addArgument('username', ['help' => 'Anmeldename', 'required' => true])
            ->addArgument('email', ['help' => 'E-Mail-Adresse', 'required' => true])
            ->addArgument('password', ['help' => 'Initiales Passwort', 'required' => true])
            ->addOption('first-name', ['help' => 'Vorname'])
            ->addOption('last-name', ['help' => 'Nachname']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $users = $this->fetchTable('Users');
        $username = (string)$args->getArgument('username');
        $email = (string)$args->getArgument('email');
        $password = (string)$args->getArgument('password');

        // Enforce the password policy from the configuration store (Step 4).
        $policyErrors = (new PasswordPolicy())->validate($password);
        if ($policyErrors) {
            $io->error(implode(' ', $policyErrors));

            return static::CODE_ERROR;
        }

        /** @var \App\Model\Entity\User|null $user */
        $user = $users->find()
            ->where(['lower(username)' => mb_strtolower($username)])
            ->first();

        if ($user === null) {
            $user = $users->newEntity([
                'username' => $username,
                'email' => $email,
                'first_name' => $args->getOption('first-name'),
                'last_name' => $args->getOption('last-name'),
            ]);
            $io->out("Neuer Benutzer wird angelegt: $username");
        } else {
            $user->email = $email;
            $io->out("Bestehender Benutzer wird aktualisiert: $username");
        }

        $user->setPassword($password);
        $user->status = User::STATUS_ACTIVE;
        $isNew = $user->isNew();

        if ($user->getErrors()) {
            $io->error('Validierung fehlgeschlagen:');
            $io->error(print_r($user->getErrors(), true));

            return static::CODE_ERROR;
        }

        $connection = $users->getConnection();
        $audit = new AuditLogger();
        $correlationId = Uuid::v7()->toRfc4122();

        // Save the user + audit in ONE transaction (transactional audit linkage,
        // ch. 1.8). Admin AREAS are no longer granted per-user: full-admin access
        // comes from membership in the tenant's Administrators (is_system) group,
        // which holds ALL areas by the wildcard rule (see below).
        $ok = $connection->transactional(function () use ($users, $user, $audit, $correlationId, $isNew): bool {
            // atomic=false: already inside this command's transactional(); avoids a
            // nested transaction whose rollback (e.g. a duplicate email caught by the
            // unique rule) would otherwise poison the outer one. A failing rule now
            // returns a clean false, surfaced as the validation error above.
            if (!$users->save($user, ['atomic' => false, 'checkRules' => true])) {
                return false;
            }
            // Audit (E16: no plaintext PII; the user is referenced by UUID).
            $audit->log(
                $isNew ? 'user.create' : 'user.update',
                'user',
                $user->id,
                ['newValue' => ['status' => $user->status], 'correlationId' => $correlationId],
            );

            return true;
        });

        if ($ok === false) {
            $io->error('Speichern fehlgeschlagen:');
            $io->error(print_r($user->getErrors(), true));

            return static::CODE_ERROR;
        }
        $areaCount = (int)$connection->execute('SELECT count(*) AS c FROM admin_areas')->fetch('assoc')['c'];

        // Membership in the tenant's ADMINISTRATOR group (ch. 25): admin areas
        // gate the admin GUI, but module resources are gated by BREAD group
        // permissions — without a group the full admin could open module admin
        // pages yet see no data. ensure() is idempotent (and tops up grants for
        // later-installed modules); the RLS context is required for the
        // permission rows' tenant_id.
        $tenantId = (string)($user->get('tenant_id') ?? TenantService::DEFAULT_TENANT_ID);
        // Name the affected TENANT explicitly: the username lookup above is
        // GLOBAL (usernames are lower()-unique platform-wide), so an operator
        // re-running create_admin can silently hit a CUSTOMER tenant's user —
        // the output must make that visible (revision-proof attribution).
        $tenantRow = $connection->execute(
            'SELECT key FROM tenants WHERE id = :t',
            ['t' => $tenantId],
        )->fetch('assoc');
        $tenantLabel = $tenantRow !== false ? (string)$tenantRow['key'] : $tenantId;
        $connection->execute("SELECT set_config('app.current_tenant_id', :t, false)", ['t' => $tenantId]);
        try {
            $groupService = new AdminGroupService();
            $group = $groupService->ensure($tenantId);
            $groupService->addUser($group['id'], (string)$user->get('id'), 'create_admin');
        } finally {
            $connection->execute("SELECT set_config('app.current_tenant_id', '', false)");
        }

        $io->success(sprintf(
            'Volladministrator "%s" (ID %s) im Mandanten "%s" gespeichert, Mitglied der Gruppe "%s" '
            . '— alle %d Administrationsbereiche (über die Gruppe) + %d Ressourcen-Grants.',
            $user->username,
            $user->id,
            $tenantLabel,
            AdminGroupService::DEFAULT_NAME,
            $areaCount,
            $group['granted'],
        ));

        return static::CODE_SUCCESS;
    }
}
