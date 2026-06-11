<?php
declare(strict_types=1);

namespace App\Service\Privacy;

/**
 * A module's contribution to user anonymization (collector
 * `core.collector.anonymize`, ch. 27.15.3).
 *
 * Invoked by the core when a user is **irreversibly anonymized**. The module
 * cleans up **its own** personal data (e.g. free-text fields in module tables)
 * relating to that user — the core does not know the module data models.
 * Technical IDs/references should be preserved (as in the core); only personal
 * content is replaced/removed.
 *
 * Runs inside the core's anonymization transaction (atomic). Out-of-process
 * modules execute their contribution in the isolated host (ch. 23.16.2).
 */
interface AnonymizeContributorInterface
{
    /**
     * Cleans up the personal data of the user `$userId` (UUID).
     *
     * @return int number of records cleaned up (for log/audit).
     */
    public function anonymizeUser(string $userId): int;
}
