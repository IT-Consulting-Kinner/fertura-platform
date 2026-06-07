<?php
/**
 * @var \App\View\AppView $this
 * @var list<array<string,mixed>> $backups
 */
$human = static function ($b): string {
    $b = (int)$b;
    if ($b <= 0) {
        return '0';
    }
    $u = ['B', 'K', 'M', 'G'];
    $i = min((int)floor(log($b, 1024)), 3);

    return round($b / 1024 ** $i, 1) . $u[$i];
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('admin.backup.title')) ?></h1>
    <?= $this->Form->create(null, ['url' => ['action' => 'create'], 'class' => 'd-flex gap-2']) ?>
        <input type="text" name="note" class="form-control form-control-sm" placeholder="<?= h(__('admin.backup.note')) ?>" style="width:220px">
        <?= $this->Form->button(__('admin.backup.create'), ['class' => 'btn btn-primary btn-sm', 'escapeTitle' => false]) ?>
    <?= $this->Form->end() ?>
</div>
<p class="text-muted small"><?= h(__('admin.backup.intro')) ?></p>
<div class="alert alert-warning small"><?= h(__('admin.backup.restore_note')) ?> <code>bin/cake backup restore &lt;id&gt; --yes</code></div>

<div class="table-responsive">
<table class="table table-sm align-middle">
    <thead><tr>
        <th><?= h(__('admin.backup.col_created')) ?></th>
        <th><?= h(__('admin.backup.col_id')) ?></th>
        <th><?= h(__('admin.backup.col_status')) ?></th>
        <th><?= h(__('admin.backup.col_db')) ?></th>
        <th><?= h(__('admin.backup.col_files')) ?></th>
        <th><?= h(__('admin.backup.col_note')) ?></th>
        <th class="text-end"><?= h(__('admin.backup.col_actions')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($backups as $b): ?>
        <tr>
            <td class="small text-muted"><?= h((string)$b['created_at']) ?></td>
            <td><code class="small"><?= h(substr((string)$b['id'], 0, 18)) ?>…</code></td>
            <td><span class="badge text-bg-<?= $b['status'] === 'complete' ? 'success' : ($b['status'] === 'failed' ? 'danger' : 'secondary') ?>"><?= h((string)$b['status']) ?></span></td>
            <td class="small"><?= h($human($b['db_bytes'])) ?></td>
            <td class="small"><?= h($human($b['files_bytes'])) ?></td>
            <td class="small"><?= h((string)($b['note'] ?? '')) ?></td>
            <td class="text-end text-nowrap">
                <?= $this->Form->postLink(__('admin.backup.verify'), ['action' => 'verify', $b['id']], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
                <?= $this->Form->postLink(__('admin.backup.test_restore'), ['action' => 'testRestore', $b['id']], ['class' => 'btn btn-outline-info btn-sm']) ?>
                <?= $this->Form->postLink(__('admin.backup.delete'), ['action' => 'delete', $b['id']],
                    ['class' => 'btn btn-outline-danger btn-sm', 'confirm' => __('admin.backup.confirm_delete')]) ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($backups === []): ?><tr><td colspan="7" class="text-muted"><?= h(__('admin.backup.empty')) ?></td></tr><?php endif; ?>
    </tbody>
</table>
</div>
