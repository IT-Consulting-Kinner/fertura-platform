<?php
declare(strict_types=1);

namespace IsolatedAnon;

use App\Service\Privacy\AnonymizeContributorInterface;
use Cake\Datasource\ConnectionManager;

/**
 * Anonymization contributor of an isolated module: scrubs its own `user_data`
 * table for the user. Runs in the isolated host under the module role (phase 3) —
 * there `ConnectionManager::get('default')` is the restricted module connection
 * with the search path set to the module schema.
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
