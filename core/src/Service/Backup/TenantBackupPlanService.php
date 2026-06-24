<?php
declare(strict_types=1);

namespace App\Service\Backup;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Per-tenant backup PLANS (Inc 7b): a tenant admin schedules recurring scoped
 * backups (e.g. ticketing daily, knowledgebase weekly). CRUD + next-run
 * computation; the scheduler (Inc 7c) consumes the due plans and runs each plan's
 * scoped backup under the owning tenant's RLS context. All queries are RLS-scoped
 * to the current tenant (the table carries a fail-closed tenant policy).
 */
class TenantBackupPlanService
{
    /** @var list<string> */
    private const CADENCES = ['daily', 'weekly', 'monthly'];

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /**
     * The current tenant's plans (RLS-scoped).
     *
     * @return list<array<string,mixed>>
     */
    public function listPlans(): array
    {
        return array_values($this->conn()->execute(
            'SELECT id, name, scope, cadence, hour, weekday, day_of_month, retention_keep, '
            . 'active, last_run_at, next_run_at FROM tenant_backup_plans '
            . 'WHERE tenant_id = core.current_tenant() ORDER BY name',
        )->fetchAll('assoc'));
    }

    /**
     * Creates a plan for the current tenant: validates scope + cadence, clamps the
     * time fields, computes the first next_run_at, and inserts (tenant_id defaults to
     * core.current_tenant()). Returns the new plan id.
     */
    public function createPlan(
        string $name,
        string $scope,
        string $cadence,
        int $hour,
        ?int $weekday,
        ?int $dayOfMonth,
        int $retentionKeep,
        bool $active,
    ): string {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException(__('flash.tenant_backup.plan_name_required'));
        }
        $scope = $this->validScope($scope);
        $cadence = in_array($cadence, self::CADENCES, true) ? $cadence : 'daily';
        $hour = max(0, min(23, $hour));
        $weekday = $weekday !== null ? max(0, min(6, $weekday)) : null;
        $dayOfMonth = $dayOfMonth !== null ? max(1, min(28, $dayOfMonth)) : null;
        $retentionKeep = max(1, min(365, $retentionKeep));
        $next = $this->computeNextRun($cadence, $hour, $weekday, $dayOfMonth);

        $row = $this->conn()->execute(
            'INSERT INTO tenant_backup_plans '
            . '(name, scope, cadence, hour, weekday, day_of_month, retention_keep, active, next_run_at) '
            . 'VALUES (:n, :sc, :c, :h, :wd, :dom, :rk, :a, :nr) RETURNING id',
            [
                'n' => $name, 'sc' => $scope, 'c' => $cadence, 'h' => $hour,
                'wd' => $weekday, 'dom' => $dayOfMonth, 'rk' => $retentionKeep,
                'a' => $active ? 'true' : 'false', 'nr' => $next->format('c'),
            ],
        )->fetch('assoc');

        return (string)$row['id'];
    }

    /**
     * Runs every DUE plan of the CURRENT tenant (Inc 7c): creates each plan's scoped
     * backup, prunes to its retention, and advances last/next_run_at. Must be called
     * with the tenant's RLS context already set (the scheduler iterates tenants and
     * sets the context per tenant). One plan's failure is isolated from the others.
     * Returns the number of plans run.
     */
    public function runDuePlans(): int
    {
        $conn = $this->conn();
        $due = $conn->execute(
            'SELECT id, name, scope, cadence, hour, weekday, day_of_month, retention_keep '
            . 'FROM tenant_backup_plans WHERE tenant_id = core.current_tenant() '
            . 'AND active AND next_run_at IS NOT NULL AND next_run_at <= now() ORDER BY next_run_at',
        )->fetchAll('assoc');

        $ran = 0;
        foreach ($due as $plan) {
            try {
                $svc = new TenantBackupService();
                $svc->create((string)$plan['scope'], 'plan: ' . (string)$plan['name'], null, (string)$plan['id']);
                $svc->pruneForPlan((string)$plan['id'], (int)$plan['retention_keep']);
                $next = $this->computeNextRun(
                    (string)$plan['cadence'],
                    (int)$plan['hour'],
                    $plan['weekday'] !== null ? (int)$plan['weekday'] : null,
                    $plan['day_of_month'] !== null ? (int)$plan['day_of_month'] : null,
                );
                $conn->execute(
                    'UPDATE tenant_backup_plans SET last_run_at = now(), next_run_at = :nr '
                    . 'WHERE id = :id AND tenant_id = core.current_tenant()',
                    ['nr' => $next->format('c'), 'id' => $plan['id']],
                );
                $ran++;
            } catch (Throwable) {
                // Isolate one plan's failure; the next cycle retries it (next_run unchanged).
            }
        }

        return $ran;
    }

    /** Deletes one of the current tenant's plans (its past backups are kept, unlinked). */
    public function deletePlan(string $id): bool
    {
        if (!$this->isUuid($id)) {
            return false;
        }

        return $this->conn()->execute(
            'DELETE FROM tenant_backup_plans WHERE id = :id AND tenant_id = core.current_tenant()',
            ['id' => $id],
        )->rowCount() > 0;
    }

    /** Enables/disables one of the current tenant's plans. */
    public function setActive(string $id, bool $active): bool
    {
        if (!$this->isUuid($id)) {
            return false;
        }

        return $this->conn()->execute(
            'UPDATE tenant_backup_plans SET active = :a WHERE id = :id AND tenant_id = core.current_tenant()',
            ['a' => $active ? 'true' : 'false', 'id' => $id],
        )->rowCount() > 0;
    }

    /**
     * The next run timestamp for a cadence/time, strictly AFTER $from (default: now).
     * day_of_month is capped at 28 by the caller/DB so monthly never overflows.
     */
    public function computeNextRun(
        string $cadence,
        int $hour,
        ?int $weekday,
        ?int $dayOfMonth,
        ?DateTimeImmutable $from = null,
    ): DateTimeImmutable {
        $from ??= new DateTimeImmutable('now');
        $hour = max(0, min(23, $hour));
        if ($cadence === 'weekly') {
            $wd = $weekday ?? 1;
            $cand = $from->setTime($hour, 0, 0);
            $diff = ($wd - (int)$cand->format('w') + 7) % 7;
            $cand = $cand->modify('+' . $diff . ' day');

            return $cand > $from ? $cand : $cand->modify('+7 day');
        }
        if ($cadence === 'monthly') {
            $dom = $dayOfMonth ?? 1;
            $cand = $from->setDate((int)$from->format('Y'), (int)$from->format('n'), $dom)->setTime($hour, 0, 0);
            if ($cand > $from) {
                return $cand;
            }
            $nm = $from->modify('first day of next month');

            return $nm->setDate((int)$nm->format('Y'), (int)$nm->format('n'), $dom)->setTime($hour, 0, 0);
        }
        // daily
        $cand = $from->setTime($hour, 0, 0);

        return $cand > $from ? $cand : $cand->modify('+1 day');
    }

    private function validScope(string $scope): string
    {
        $scope = trim($scope) !== '' ? trim($scope) : 'full';
        if (!in_array($scope, (new TenantBackupService())->availableScopes(), true)) {
            throw new RuntimeException(__('flash.tenant_backup.invalid_scope'));
        }

        return $scope;
    }

    private function isUuid(string $v): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1;
    }
}
