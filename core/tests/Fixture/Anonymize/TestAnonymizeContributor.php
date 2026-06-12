<?php
declare(strict_types=1);

namespace App\Test\Fixture\Anonymize;

use App\Service\Privacy\AnonymizeContributorInterface;
use Cake\Datasource\ConnectionManager;

/**
 * Test anonymization contributor: scrubs the free-text column of a test table
 * (`public.ztest_anon_data`) for the given user.
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
