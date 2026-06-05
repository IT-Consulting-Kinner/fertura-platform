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

        $stats = [
            'users_active' => $scalar("SELECT count(*) c FROM users WHERE status = 'active'"),
            'groups_active' => $scalar('SELECT count(*) c FROM "groups" WHERE active'),
            'modules_active' => $scalar("SELECT count(*) c FROM modules WHERE status = 'active'"),
            'modules_total' => $scalar('SELECT count(*) c FROM modules'),
            'contracts' => $scalar('SELECT count(*) c FROM contracts WHERE active'),
            'outbox_pending' => $scalar("SELECT count(*) c FROM event_outbox WHERE status = 'pending'"),
            'outbox_deadletter' => $scalar("SELECT count(*) c FROM event_outbox WHERE status = 'dead_letter'"),
            'licenses' => $scalar('SELECT count(*) c FROM licenses'),
        ];

        $modulesByStatus = $conn->execute(
            'SELECT status, count(*) AS c FROM modules GROUP BY status ORDER BY status',
        )->fetchAll('assoc');

        $this->set(compact('stats', 'modulesByStatus'));
    }
}
