<?php
declare(strict_types=1);

namespace IsolatedAnon;

use App\Service\Privacy\AnonymizeContributorInterface;
use Cake\Datasource\ConnectionManager;

/**
 * Anonymisierungs-Beitrag eines isolierten Moduls: bereinigt die eigene
 * Tabelle `user_data` für den Benutzer. Läuft im isolierten Host über die
 * Modul-Rolle (Phase 3) — `ConnectionManager::get('default')` ist dort die
 * eingeschränkte Modul-Connection mit Search-Path auf das Modul-Schema.
 */
class AnonContributor implements AnonymizeContributorInterface
{
    public function anonymizeUser(string $userId): int
    {
        $stmt = ConnectionManager::get('default')->execute(
            "UPDATE user_data SET note = '[anonymisiert]' WHERE owner_id = :u AND note <> '[anonymisiert]'",
            ['u' => $userId],
        );

        return $stmt->rowCount();
    }
}
