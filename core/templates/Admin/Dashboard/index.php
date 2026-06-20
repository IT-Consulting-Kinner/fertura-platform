<?php
/**
 * @var \App\View\AppView $this
 * @var array<string,int> $stats
 * @var array<int,array<string,mixed>> $modulesByStatus
 */
$this->assign('title', __('admin.dashboard.title'));
$cards = [
    ['key' => 'users_active', 'label' => __('admin.dashboard.card_users_active'), 'value' => $stats['users_active']],
    ['key' => 'groups_active', 'label' => __('admin.dashboard.card_groups_active'), 'value' => $stats['groups_active']],
    ['key' => 'modules_active', 'label' => __('admin.dashboard.card_modules_active'), 'value' => $stats['modules_active'] . ' / ' . $stats['modules_total']],
    ['key' => 'contracts', 'label' => __('admin.dashboard.card_contracts'), 'value' => $stats['contracts']],
    ['key' => 'outbox_pending', 'label' => __('admin.dashboard.card_outbox_pending'), 'value' => $stats['outbox_pending']],
    ['key' => 'outbox_deadletter', 'label' => __('admin.dashboard.card_deadletter'), 'value' => $stats['outbox_deadletter']],
    ['key' => 'licenses', 'label' => __('admin.dashboard.card_licenses'), 'value' => $stats['licenses']],
];
?>
<h1 class="h3 mb-4"><?= h(__('admin.dashboard.title')) ?></h1>
<div class="row g-3">
    <?php foreach ($cards as $card): ?>
        <div class="col-sm-6 col-lg-3">
            <div class="card<?= $card['key'] === 'outbox_deadletter' && (int)$card['value'] > 0 ? ' border-danger' : '' ?>">
                <div class="card-body">
                    <div class="text-muted small text-uppercase"><?= h($card['label']) ?></div>
                    <div class="display-6"><?= h((string)$card['value']) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<h2 class="h5 mt-4"><?= h(__('admin.dashboard.modules_by_status')) ?></h2>
<table class="table table-sm w-auto">
    <?php foreach ($modulesByStatus as $row): ?>
        <tr><td class="pe-4"><span class="badge text-bg-<?= $row['status'] === 'active' ? 'success' : 'secondary' ?>"><?= h(__('admin.module.status_' . $row['status'])) ?></span></td><td class="fw-semibold"><?= h((string)$row['c']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$modulesByStatus): ?><tr><td class="text-muted"><?= h(__('admin.dashboard.no_modules')) ?></td></tr><?php endif; ?>
</table>
