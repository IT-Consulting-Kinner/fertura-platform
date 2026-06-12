<?php
declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditLogger;
use App\Auth\PasswordPolicy;
use App\Model\Entity\User;
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

        // Save, assign areas and write audit entries in ONE transaction
        // (transactional audit linkage, ch. 1.8).
        $count = $connection->transactional(function () use (
            $users,
            $user,
            $connection,
            $audit,
            $correlationId,
            $isNew,
        ) {
            if (!$users->save($user, ['checkRules' => true])) {
                return false;
            }

            $connection->execute(
                'INSERT INTO user_admin_areas (user_id, admin_area_key) ' .
                'SELECT :uid, area_key FROM admin_areas ' .
                'ON CONFLICT (user_id, admin_area_key) DO NOTHING',
                ['uid' => $user->id],
            );

            $areaKeys = $connection
                ->execute('SELECT admin_area_key FROM user_admin_areas WHERE user_id = :uid ORDER BY admin_area_key', ['uid' => $user->id])
                ->fetchAll('assoc');
            $areaKeys = array_column($areaKeys, 'admin_area_key');

            // Audit (E16: no plaintext PII; the user is referenced by UUID).
            $audit->log(
                $isNew ? 'user.create' : 'user.update',
                'user',
                $user->id,
                ['newValue' => ['status' => $user->status], 'correlationId' => $correlationId],
            );
            $audit->log('admin_access.grant', 'user', $user->id, [
                'newValue' => ['admin_areas' => $areaKeys],
                'correlationId' => $correlationId,
            ]);

            return count($areaKeys);
        });

        if ($count === false) {
            $io->error('Speichern fehlgeschlagen:');
            $io->error(print_r($user->getErrors(), true));

            return static::CODE_ERROR;
        }

        $io->success(sprintf(
            'Volladministrator "%s" (ID %s) gespeichert, %d Administrationsbereiche zugewiesen.',
            $user->username,
            $user->id,
            $count,
        ));

        return static::CODE_SUCCESS;
    }
}
