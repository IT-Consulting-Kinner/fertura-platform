<?php
/**
 * @var \App\View\AppView $this
 * @var list<array<string,mixed>> $backups
 */
?>
<h1 class="h3 mb-1"><?= h(__('admin.tenant_backup.title')) ?></h1>
<p class="text-muted small"><?= h(__('admin.tenant_backup.hint')) ?></p>

<?= $this->Form->create(null, ['url' => ['action' => 'create'], 'class' => 'row g-2 align-items-end col-md-9 mb-4']) ?>
    <div class="col">
        <label class="form-label small mb-0"><?= h(__('admin.tenant_backup.note')) ?></label>
        <?= $this->Form->control('note', ['label' => false, 'class' => 'form-control form-control-sm']) ?>
    </div>
    <div class="col-auto"><?= $this->Form->button(__('admin.tenant_backup.create'), ['class' => 'btn btn-primary btn-sm']) ?></div>
<?= $this->Form->end() ?>

<table class="table table-sm table-hover align-middle">
    <thead><tr>
        <th scope="col"><?= h(__('admin.tenant_backup.col_created')) ?></th>
        <th scope="col"><?= h(__('admin.tenant_backup.col_file')) ?></th>
        <th scope="col"><?= h(__('admin.tenant_backup.col_size')) ?></th>
        <th scope="col"><?= h(__('admin.tenant_backup.col_encrypted')) ?></th>
        <th scope="col"><?= h(__('admin.tenant_backup.col_note')) ?></th>
        <th scope="col" class="text-end"><?= h(__('admin.tenant_backup.col_action')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($backups as $b): ?>
        <tr>
            <td class="small"><?= $this->UiKit->value($b['created_at'], 'datetime') ?></td>
            <td><code class="small"><?= h((string)$b['filename']) ?></code></td>
            <td class="small"><?= h((string)(int)round(((int)$b['bytes']) / 1024)) ?> KB</td>
            <td><?= $this->UiKit->value($b['encrypted'], 'bool') ?></td>
            <td class="small text-muted"><?= h((string)($b['note'] ?? '')) ?></td>
            <td class="text-end">
                <?= $this->Form->create(null, ['url' => ['action' => 'download', $b['id']], 'class' => 'd-inline']) ?>
                <?= $this->Form->button(
                    __('admin.tenant_backup.download'),
                    ['class' => 'btn btn-sm btn-outline-secondary', 'type' => 'submit'],
                ) ?>
                <?= $this->Form->end() ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($backups === []): ?>
        <tr><td colspan="6" class="text-muted"><?= h(__('admin.tenant_backup.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
