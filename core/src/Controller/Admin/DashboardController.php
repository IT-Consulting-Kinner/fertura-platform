<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Dashboard\DashboardTileCollector;
use Cake\Datasource\ConnectionManager;

/**
 * Admin-Dashboard: Betriebsstatus (Kap. 20.2.4).
 *
 * The tiles are arranged as accordion groups (all expanded): the operator/core
 * group first, then one group per module that contributes tiles via the
 * `core.collector.dashboard_tiles` collector (Paket 4).
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

        // Core/operator group: tenant figures for everyone, platform inventory for
        // operators. Rendered as the FIRST accordion group (Paket 4).
        $coreTiles = [
            ['label' => __('admin.dashboard.card_users_active'), 'value' => (string)$scalar(
                "SELECT count(*) c FROM users WHERE status = 'active' AND tenant_id = core.current_tenant()",
            )],
            ['label' => __('admin.dashboard.card_groups_active'), 'value' => (string)$scalar(
                'SELECT count(*) c FROM "groups" WHERE active',
            )],
        ];
        if ($isOperator) {
            $deadLetter = $scalar("SELECT count(*) c FROM event_outbox WHERE status = 'dead_letter'");
            $coreTiles[] = ['label' => __('admin.dashboard.card_modules_active'), 'value' =>
                $scalar("SELECT count(*) c FROM modules WHERE status = 'active'") . ' / ' . $scalar('SELECT count(*) c FROM modules')];
            $coreTiles[] = ['label' => __('admin.dashboard.card_contracts'), 'value' => (string)$scalar(
                'SELECT count(*) c FROM contracts WHERE active',
            )];
            $coreTiles[] = ['label' => __('admin.dashboard.card_outbox_pending'), 'value' => (string)$scalar(
                "SELECT count(*) c FROM event_outbox WHERE status = 'pending'",
            )];
            $coreTiles[] = ['label' => __('admin.dashboard.card_deadletter'), 'value' => (string)$deadLetter,
                'variant' => $deadLetter > 0 ? 'danger' : null];
            $coreTiles[] = ['label' => __('admin.dashboard.card_licenses'), 'value' => (string)$scalar(
                'SELECT count(*) c FROM licenses',
            )];
        }

        // Groups: operator/core first, then one per contributing module.
        $groups = [[
            'key' => 'core',
            'name' => __($isOperator ? 'admin.dashboard.group_operator' : 'admin.dashboard.group_overview'),
            'tiles' => $coreTiles,
        ]];
        foreach ((new DashboardTileCollector())->collect(['is_operator' => $isOperator]) as $moduleGroup) {
            $groups[] = $moduleGroup;
        }

        $this->set(compact('groups', 'isOperator'));
    }
}
