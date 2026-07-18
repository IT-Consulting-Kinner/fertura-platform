<?php
/**
 * @var \App\View\AppView $this
 * @var array<string,int> $stats
 * @var bool $isOperator
 */
$this->assign('title', __('admin.dashboard.title'));
// Tenant-relevant cards for every admin; operator-only platform inventory is added
// solely for operator admins (the controller omits those stats for tenant admins).
$cards = [
    ['key' => 'users_active', 'label' => __('admin.dashboard.card_users_active'), 'value' => $stats['users_active']],
    ['key' => 'groups_active', 'label' => __('admin.dashboard.card_groups_active'), 'value' => $stats['groups_active']],
];
if ($isOperator) {
    $cards[] = ['key' => 'modules_active', 'label' => __('admin.dashboard.card_modules_active'), 'value' => $stats['modules_active'] . ' / ' . $stats['modules_total']];
    $cards[] = ['key' => 'contracts', 'label' => __('admin.dashboard.card_contracts'), 'value' => $stats['contracts']];
    $cards[] = ['key' => 'outbox_pending', 'label' => __('admin.dashboard.card_outbox_pending'), 'value' => $stats['outbox_pending']];
    $cards[] = ['key' => 'outbox_deadletter', 'label' => __('admin.dashboard.card_deadletter'), 'value' => $stats['outbox_deadletter']];
    $cards[] = ['key' => 'licenses', 'label' => __('admin.dashboard.card_licenses'), 'value' => $stats['licenses']];
}
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
