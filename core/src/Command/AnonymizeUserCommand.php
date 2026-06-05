<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\Entity\User;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Setzt das Recht auf Löschung um (Entscheidung 160 / Kap. 27.15.3):
 * - aktivierte/deaktivierte Benutzer werden irreversibel anonymisiert,
 * - noch nicht aktivierte Einladungs-Accounts (Status invited) dürfen
 *   physisch gelöscht werden (keine Fachdaten erzeugt).
 */
class AnonymizeUserCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription('Anonymisiert einen Benutzer (oder löscht einen Einladungs-Account).')
            ->addArgument('user', ['help' => 'Benutzername oder numerische ID', 'required' => true]);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $users = $this->fetchTable('Users');
        $ref = (string)$args->getArgument('user');

        $query = $users->find();
        if (ctype_digit($ref)) {
            $query->where(['id' => (int)$ref]);
        } else {
            $query->where(['lower(username)' => mb_strtolower($ref)]);
        }
        /** @var \App\Model\Entity\User|null $user */
        $user = $query->first();

        if ($user === null) {
            $io->error("Benutzer nicht gefunden: $ref");

            return static::CODE_ERROR;
        }

        if ($user->status === User::STATUS_ANONYMIZED) {
            $io->warning("Benutzer #{$user->id} ist bereits anonymisiert.");

            return static::CODE_SUCCESS;
        }

        // Einladungs-Accounts: physische Löschung zulässig (keine Fachdaten).
        if ($user->status === User::STATUS_INVITED) {
            if ($users->delete($user)) {
                $io->success("Einladungs-Account #{$user->id} ($ref) physisch gelöscht.");

                return static::CODE_SUCCESS;
            }
            $io->error('Löschen fehlgeschlagen.');

            return static::CODE_ERROR;
        }

        $id = $user->id;
        if ($users->anonymize($user)) {
            $io->success("Benutzer #$id irreversibel anonymisiert (ID + Referenzen bleiben).");

            return static::CODE_SUCCESS;
        }

        $io->error('Anonymisierung fehlgeschlagen.');

        return static::CODE_ERROR;
    }
}
