<?php
declare(strict_types=1);

namespace App\Service\Permission;

use Cake\Database\Connection;

/**
 * Sets the row-level-security access context per transaction via SET LOCAL
 * (pooling-compatible, Decision 175). MUST be called within a transaction.
 *
 * Session variables:
 *   app.current_user_id, app.current_group_ids (CSV uuids), app.bypass_rls,
 *   app.current_tenant_id (tenant; empty = no tenant context -> tenant-scoped
 *   data is invisible, fail-closed)
 */
class RlsContext
{
    /**
     * @param list<string> $groupIds
     */
    public function applyLocal(
        Connection $connection,
        ?string $userId,
        array $groupIds,
        bool $bypass = false,
        ?string $tenantId = null,
    ): void {
        $connection->execute("SELECT set_config('app.current_user_id', :u, true)", ['u' => $userId ?? '']);
        $connection->execute("SELECT set_config('app.current_group_ids', :g, true)", ['g' => implode(',', $groupIds)]);
        $connection->execute("SELECT set_config('app.bypass_rls', :b, true)", ['b' => $bypass ? 'true' : 'false']);
        $connection->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $tenantId ?? '']);
    }
}
