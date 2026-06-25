<?php
declare(strict_types=1);

namespace App\Service\Consumption;

use App\Service\Backup\TenantBackupService;
use App\Service\Module\TenantModuleService;
use App\Service\Storage\StorageManager;
use App\Service\Storage\TenantStorage;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Aggregates what the CURRENT tenant consumes — enabled FUNCTIONS (modules) and
 * STORAGE (backup archives + per-tenant file store + approximate DB footprint) —
 * for the tenant consumption dashboard (Inc 7d) and, run once per tenant under that
 * tenant's RLS context, the operator's per-tenant billing view (Inc 7e).
 *
 * One code path (Decision 185): every figure derives from `core.current_tenant()`,
 * so the tenant admin sees their own usage and the operator iterates the tenants,
 * setting each one's context before calling — there is never a second, cross-tenant
 * query shape that could leak one tenant's totals into another's view.
 */
class TenantConsumptionService
{
    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /** The request's tenant from the RLS context; '' when unset (fail-closed). */
    private function currentTenantId(): string
    {
        $row = $this->conn()->execute('SELECT core.current_tenant() AS t')->fetch('assoc');

        return $row !== false && $row['t'] !== null ? (string)$row['t'] : '';
    }

    /**
     * The current tenant's consumption summary, or null when there is no tenant
     * context (fail-closed — the caller then renders an empty state rather than
     * platform-wide totals). `total_bytes` is the billable storage = backup archives
     * + the per-tenant file store (the DB footprint is reported separately as an
     * approximate row count, not a byte size).
     *
     * @return array{
     *     tenant_id: string,
     *     modules: list<array{module_key:string, name:string, version:string}>,
     *     module_count: int,
     *     storage: array{backup_bytes:int, backup_count:int, file_bytes:int, file_count:int, total_bytes:int},
     *     footprint: array{total_rows:int, tables:list<array{key:string, rows:int}>}
     * }|null
     */
    public function summary(): ?array
    {
        $tenantId = $this->currentTenantId();
        if ($tenantId === '') {
            return null;
        }

        $modules = (new TenantModuleService())->enabledModules();
        $backupSvc = new TenantBackupService();
        $archives = $backupSvc->archiveStats();
        $footprint = $backupSvc->footprint();
        [$fileCount, $fileBytes] = $this->fileStoreSize($tenantId);

        $totalRows = 0;
        foreach ($footprint as $f) {
            $totalRows += $f['rows'];
        }
        // Only the tables that actually hold rows keep the footprint table readable;
        // an empty tenant still shows total_rows = 0.
        $nonEmpty = array_values(array_filter($footprint, static fn(array $f): bool => $f['rows'] > 0));

        return [
            'tenant_id' => $tenantId,
            'modules' => $modules,
            'module_count' => count($modules),
            'storage' => [
                'backup_bytes' => $archives['bytes'],
                'backup_count' => $archives['count'],
                'file_bytes' => $fileBytes,
                'file_count' => $fileCount,
                'total_bytes' => $archives['bytes'] + $fileBytes,
            ],
            'footprint' => [
                'total_rows' => $totalRows,
                'tables' => $nonEmpty,
            ],
        ];
    }

    /**
     * Sums the byte size + file count of the current tenant's object-storage files
     * under its prefix (`tenant/<id>/`). Storage errors degrade gracefully — a
     * backend that cannot list yields zero, and a file that vanished mid-scan is
     * skipped — so a transient storage hiccup never blanks the whole dashboard.
     * These are filesystem/Flysystem errors, not DB errors, so the catch carries no
     * request-transaction-poisoning risk.
     *
     * @return array{0:int, 1:int} [count, bytes]
     */
    private function fileStoreSize(string $tenantId): array
    {
        $storage = new StorageManager();
        try {
            $files = $storage->list(TenantStorage::prefix($tenantId), true);
        } catch (Throwable) {
            return [0, 0];
        }
        $bytes = 0;
        $count = 0;
        foreach ($files as $path) {
            try {
                $bytes += $storage->fileSize((string)$path);
                $count++;
            } catch (Throwable) {
                // Listed but no longer sizable (deleted between list and stat) — skip.
            }
        }

        return [$count, $bytes];
    }
}
