<?php
declare(strict_types=1);

namespace App\Service\Privacy;

use App\Service\Module\ContributionRuntime;
use App\Service\Registry\ContractRegistry;
use Cake\Database\Connection;
use Throwable;

/**
 * Orchestrates module participation in user anonymization (ch. 27.15.3). When a
 * user is irreversibly anonymized, the core invokes every registered module via
 * the collector `core.collector.anonymize` so that it cleans up **its own**
 * personal data relating to that user.
 *
 * Runs inside the anonymization transaction (atomic, all-or-nothing): if a
 * contribution fails, the entire anonymization fails — better loud than an
 * incomplete deletion. For the duration of the contributions `app.bypass_rls`
 * is set (a privileged core operation with no running user context, so the
 * contributions can reach the target user's rows) and afterwards reset to its
 * previous value.
 */
class AnonymizationService
{
    public const COLLECTOR = 'core.collector.anonymize';

    public function __construct(private ?ContractRegistry $registry = null)
    {
        $this->registry ??= new ContractRegistry();
    }

    /**
     * @return int sum of records cleaned up by all modules.
     */
    public function run(string $userId, Connection $conn): int
    {
        $runtime = new ContributionRuntime($this->registry);
        try {
            // Privacy erasure must reach EVERY module that may hold the user's data,
            // regardless of the acting context's tenant or per-tenant enablement
            // (un-gated, Phase 2); runs RLS-bypassed below. A module with no data
            // for the user is a harmless no-op.
            $contribs = $runtime->collectors(self::COLLECTOR, false);
        } catch (Throwable) {
            // Contract not (yet) present -> no module contributions.
            return 0;
        }
        if ($contribs === []) {
            return 0;
        }

        // In-process contributions use the ambient context of this connection ->
        // set bypass here; out-of-process contributions receive bypass via RPC.
        $prev = (string)($conn->execute("SELECT current_setting('app.bypass_rls', true) AS v")->fetch('assoc')['v'] ?? '');
        $conn->execute("SELECT set_config('app.bypass_rls', 'true', true)");
        try {
            $total = 0;
            foreach ($contribs as $contrib) {
                $total += (int)$runtime->call($contrib, 'anonymizeUser', [$userId], ['bypass' => true]);
            }

            return $total;
        } finally {
            $conn->execute("SELECT set_config('app.bypass_rls', :v, true)", ['v' => $prev === '' ? 'false' : $prev]);
        }
    }
}
