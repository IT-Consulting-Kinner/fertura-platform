<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\Entity\User;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Legt einen Volladministrator an (oder aktualisiert ihn) und weist ihm alle
 * Core-Administrationsbereiche zu. Macht den Core ohne weiteres Modul nutzbar.
 *
 * Beispiel:
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

        if ($user->getErrors()) {
            $io->error('Validierung fehlgeschlagen:');
            $io->error(print_r($user->getErrors(), true));

            return static::CODE_ERROR;
        }

        if (!$users->save($user, ['checkRules' => true])) {
            $io->error('Speichern fehlgeschlagen:');
            $io->error(print_r($user->getErrors(), true));

            return static::CODE_ERROR;
        }

        // Alle Administrationsbereiche zuweisen (Volladministrator).
        $connection = $users->getConnection();
        $connection->execute(
            'INSERT INTO user_admin_areas (user_id, admin_area_key) ' .
            'SELECT :uid, area_key FROM admin_areas ' .
            'ON CONFLICT (user_id, admin_area_key) DO NOTHING',
            ['uid' => $user->id],
        );

        $count = $connection
            ->execute('SELECT count(*) AS c FROM user_admin_areas WHERE user_id = :uid', ['uid' => $user->id])
            ->fetch('assoc');

        $io->success(sprintf(
            'Volladministrator "%s" (ID %s) gespeichert, %s Administrationsbereiche zugewiesen.',
            $user->username,
            $user->id,
            $count['c'] ?? '?',
        ));

        return static::CODE_SUCCESS;
    }
}
