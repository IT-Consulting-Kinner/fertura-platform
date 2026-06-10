<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $history
 * @var array<int, array<string, mixed>> $modules
 * @var string $coreVersion
 */
$resBadge = ['success' => 'success', 'failed' => 'danger', 'rolled_back' => 'warning'];
?>
<h1 class="h3 mb-3"><?= h(__('admin.updates.title')) ?></h1>

<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card"><div class="card-header"><?= h(__('admin.updates.module_update')) ?></div><div class="card-body">
            <?= $this->Form->create(null, ['url' => ['action' => 'previewModule']]) ?>
            <div class="mb-2"><label class="form-label"><?= h(__('admin.updates.module')) ?></label>
                <?= $this->Form->select('module_key', array_combine(array_column($modules, 'module_key'),
                    array_map(fn ($m) => $m['module_key'] . ' (' . $m['version'] . ')', $modules)),
                    ['class' => 'form-select', 'empty' => __('admin.updates.choose'), 'required' => true]) ?></div>
            <div class="mb-2"><label class="form-label"><?= h(__('admin.updates.package_path')) ?></label>
                <?= $this->Form->control('source_path', ['label' => false, 'class' => 'form-control', 'placeholder' => '/pfad/zum/paket', 'required' => true]) ?></div>
            <?= $this->Form->button(__('admin.updates.preview'), ['class' => 'btn btn-primary btn-sm']) ?>
            <?= $this->Form->end() ?>
        </div></div>
    </div>
    <div class="col-md-6">
        <div class="card"><div class="card-header"><?= h(__('admin.updates.core_update')) ?> <span class="text-muted small"><?= h(__('admin.updates.current', $coreVersion)) ?></span></div><div class="card-body">
            <?= $this->Form->create(null, ['url' => ['action' => 'previewCore']]) ?>
            <div class="mb-2"><label class="form-label"><?= h(__('admin.updates.target_version')) ?></label>
                <?= $this->Form->control('target_version', ['label' => false, 'class' => 'form-control', 'placeholder' => __('admin.updates.target_version_placeholder'), 'required' => true]) ?></div>
            <div class="form-check mb-2"><?= $this->Form->checkbox('force', ['class' => 'form-check-input', 'id' => 'force']) ?>
                <label class="form-check-label" for="force"><?= h(__('admin.updates.force')) ?></label></div>
            <?= $this->Form->button(__('admin.updates.preview'), ['class' => 'btn btn-warning btn-sm']) ?>
            <?= $this->Form->end() ?>
        </div></div>
    </div>
</div>

<h2 class="h5"><?= h(__('admin.updates.history')) ?></h2>
<table class="table table-sm table-hover align-middle">
    <thead><tr><th scope="col"><?= h(__('admin.updates.col_time')) ?></th><th scope="col"><?= h(__('admin.updates.col_type')) ?></th><th scope="col"><?= h(__('admin.updates.col_component')) ?></th><th scope="col"><?= h(__('admin.updates.col_from')) ?></th><th scope="col"><?= h(__('admin.updates.col_to')) ?></th><th scope="col"><?= h(__('admin.updates.col_result')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
        <tr>
            <td class="small"><?= h((string)$h['executed_at']) ?></td>
            <td><?= h($h['component_type']) ?></td>
            <td><code><?= h($h['component_key']) ?></code>
                <?php if (filter_var($h['is_security'] ?? false, FILTER_VALIDATE_BOOLEAN)): ?>
                    <span class="badge text-bg-danger"><?= h(__('admin.updates.security')) ?><?= !empty($h['severity']) ? ': ' . h($h['severity']) : '' ?></span>
                <?php endif; ?></td>
            <td><?= h($h['old_version']) ?: '–' ?></td>
            <td><?= h($h['new_version']) ?: '–' ?></td>
            <td><span class="badge text-bg-<?= $resBadge[$h['result']] ?? 'secondary' ?>"><?= h($h['result']) ?></span></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($history === []): ?><tr><td colspan="6" class="text-muted"><?= h(__('admin.updates.no_history')) ?></td></tr><?php endif; ?>
    </tbody>
</table>
