<?php
declare(strict_types=1);

namespace App\Test\Fixture\Anonymize;

use App\Service\Privacy\AnonymizeContributorInterface;
use Cake\Datasource\ConnectionManager;

/**
 * Test-Beitrag zur Anonymisierung: bereinigt die Freitextspalte einer
 * Testtabelle (`public.ztest_anon_data`) für den gegebenen Benutzer.
 */
class TestAnonymizeContributor implements AnonymizeContributorInterface
{
    public function anonymizeUser(string $userId): int
    {
        $stmt = ConnectionManager::get('default')->execute(
            "UPDATE public.ztest_anon_data SET secret = '[anonymisiert]' "
            . "WHERE owner_id = :u AND secret <> '[anonymisiert]'",
            ['u' => $userId],
        );

        return $stmt->rowCount();
    }
}
