<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use Cake\Datasource\ConnectionManager;

/**
 * Admin-Dashboard: Betriebsstatus (Kap. 20.2.4).
 */
class DashboardController extends AdminController
{
    public function index(): void
    {
        $conn = ConnectionManager::get('default');

        $scalar = static function (string $sql) use ($conn): int {
            $row = $conn->execute($sql)->fetch('assoc');

            return (int)($row['c'] ?? 0);
        };

        // The /admin landing is reachable by EVERY admin (incl. tenant admins), so it
        // must not surface operator/platform-wide data. Tenant-relevant figures only:
        // users has no RLS -> scope explicitly to the current tenant; groups is
        // RLS-scoped to the current tenant already.
        $isOperator = $this->isOperatorTenant();
        $stats = [
            'users_active' => $scalar("SELECT count(*) c FROM users WHERE status = 'active' AND tenant_id = core.current_tenant()"),
            'groups_active' => $scalar('SELECT count(*) c FROM "groups" WHERE active'),
        ];

        // Platform-wide inventory (modules/contracts/outbox/licenses are NOT
        // tenant-scoped): operator admins only.
        if ($isOperator) {
            $stats += [
                'modules_active' => $scalar("SELECT count(*) c FROM modules WHERE status = 'active'"),
                'modules_total' => $scalar('SELECT count(*) c FROM modules'),
                'contracts' => $scalar('SELECT count(*) c FROM contracts WHERE active'),
                'outbox_pending' => $scalar("SELECT count(*) c FROM event_outbox WHERE status = 'pending'"),
                'outbox_deadletter' => $scalar("SELECT count(*) c FROM event_outbox WHERE status = 'dead_letter'"),
                'licenses' => $scalar('SELECT count(*) c FROM licenses'),
            ];
        }

        $this->set(compact('stats', 'isOperator'));
    }
}
