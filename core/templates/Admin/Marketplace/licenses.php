<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $licenses
 */
$evalBadge = ['valid' => 'success', 'grace' => 'warning', 'needs_online' => 'warning', 'expired' => 'danger', 'revoked' => 'danger', 'missing' => 'secondary'];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('admin.marketplace.licenses_title')) ?></h1>
    <?= $this->Html->link('&laquo; ' . h(__('admin.marketplace.back_to_marketplace')), ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>

<table class="table table-hover align-middle">
    <thead><tr><th><?= h(__('admin.marketplace.col_module')) ?></th><th><?= h(__('admin.marketplace.col_key_id')) ?></th><th><?= h(__('admin.marketplace.col_valid_to')) ?></th><th><?= h(__('admin.marketplace.col_online')) ?></th><th><?= h(__('admin.marketplace.col_status_db')) ?></th><th><?= h(__('admin.marketplace.col_evaluated')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($licenses as $l): ?>
        <tr>
            <td><code><?= h($l['module_key']) ?></code></td>
            <td class="small text-muted"><?= h($l['signed_key_id']) ?></td>
            <td><?= h((string)$l['valid_to']) ?: '–' ?></td>
            <td><?= filter_var($l['online_enforcement'], FILTER_VALIDATE_BOOLEAN) ? h(__('admin.marketplace.yes')) : h(__('admin.marketplace.no')) ?></td>
            <td><?= h($l['status']) ?></td>
            <td><span class="badge text-bg-<?= $evalBadge[$l['evaluated']] ?? 'secondary' ?>"><?= h($l['evaluated']) ?></span></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($licenses === []): ?><tr><td colspan="6" class="text-muted"><?= h(__('admin.marketplace.no_licenses')) ?></td></tr><?php endif; ?>
    </tbody>
</table>

<div class="card mt-4"><div class="card-header"><?= h(__('admin.marketplace.install_license')) ?></div><div class="card-body">
    <?= $this->Form->create(null, ['url' => ['action' => 'uploadLicense'], 'type' => 'file']) ?>
    <div class="mb-3"><label class="form-label"><?= h(__('admin.marketplace.license_file_label')) ?></label>
        <?= $this->Form->control('license_file', ['type' => 'file', 'label' => false, 'class' => 'form-control', 'accept' => 'application/json,.json']) ?></div>
    <div class="mb-3"><label class="form-label"><?= h(__('admin.marketplace.license_json_label')) ?></label>
        <?= $this->Form->control('license_json', ['type' => 'textarea', 'label' => false, 'class' => 'form-control font-monospace', 'rows' => 4]) ?></div>
    <?= $this->Form->button(__('admin.marketplace.install'), ['class' => 'btn btn-primary btn-sm']) ?>
    <?= $this->Form->end() ?>
</div></div>
