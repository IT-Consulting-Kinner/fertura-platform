<?php
/**
 * @var \App\View\AppView $this
 * @var array<string, mixed> $preview
 * @var string $sourcePath
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('admin.updates.module_preview_title')) ?></h1>
    <?= $this->Html->link('&laquo; ' . h(__('admin.updates.cancel')), ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>

<div class="card">
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3"><?= h(__('admin.updates.module')) ?></dt><dd class="col-sm-9"><code><?= h($preview['module_key']) ?></code></dd>
            <dt class="col-sm-3"><?= h(__('admin.updates.version')) ?></dt><dd class="col-sm-9"><?= h($preview['current_version'] ?? '?') ?> &rarr; <strong><?= h($preview['new_version'] ?? '?') ?></strong>
                <?php if (!empty($preview['is_downgrade'])): ?><span class="badge text-bg-danger"><?= h(__('admin.updates.downgrade')) ?></span><?php endif; ?>
                <?php if (!empty($preview['is_security'])): ?><span class="badge text-bg-danger"><?= h(__('admin.updates.security_update')) ?></span><?php endif; ?></dd>
            <dt class="col-sm-3"><?= h(__('admin.updates.pending_migrations')) ?></dt>
            <dd class="col-sm-9">
                <?php $pm = $preview['pending_migrations'] ?? []; ?>
                <?php if ($pm === []): ?><span class="text-muted"><?= h(__('admin.updates.none')) ?></span>
                <?php else: ?><ul class="mb-0"><?php foreach ($pm as $m): ?><li><code class="small"><?= h($m) ?></code></li><?php endforeach; ?></ul><?php endif; ?>
            </dd>
        </dl>

        <?php if (!empty($preview['errors'])): ?>
            <div class="alert alert-danger mt-3 mb-0">
                <strong><?= h(__('admin.updates.blocking_issues')) ?></strong>
                <ul class="mb-0"><?php foreach ($preview['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-footer d-flex gap-2">
        <?php if (!empty($preview['ok'])): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'module']]) ?>
                <?= $this->Form->hidden('module_key', ['value' => $preview['module_key']]) ?>
                <?= $this->Form->hidden('source_path', ['value' => $sourcePath]) ?>
                <?= $this->Form->button(__('admin.updates.run_now'), ['class' => 'btn btn-primary btn-sm', 'data-confirm' => __('admin.updates.confirm_module'), 'data-confirm-variant' => 'btn-warning']) ?>
            <?= $this->Form->end() ?>
        <?php else: ?>
            <span class="text-danger small align-self-center"><?= h(__('admin.updates.blocked_fix')) ?></span>
        <?php endif; ?>
        <?= $this->Html->link(__('admin.updates.cancel'), ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
    </div>
</div>
