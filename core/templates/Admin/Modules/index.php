<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $modules
 * @var array<int, array<string, mixed>> $deps
 */
$badge = ['active' => 'success', 'installed_inactive' => 'secondary', 'installed' => 'secondary', 'error' => 'danger'];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('admin.modules.title')) ?></h1>
    <?= $this->Html->link(__('admin.modules.show_graph'), ['action' => 'graph'], ['class' => 'btn btn-outline-primary btn-sm']) ?>
</div>
<p class="text-muted small"><?= __('admin.modules.intro', '<code>bin/cake module install</code>') ?></p>
<table class="table table-hover align-middle">
    <thead><tr><th scope="col"><?= h(__('admin.modules.col_key')) ?></th><th scope="col"><?= h(__('admin.modules.col_name')) ?></th><th scope="col"><?= h(__('admin.modules.col_version')) ?></th><th scope="col"><?= h(__('admin.modules.col_type')) ?></th><th scope="col"><?= h(__('admin.modules.col_license')) ?></th><th scope="col"><?= h(__('admin.modules.col_status')) ?></th><th scope="col" class="text-end"><?= h(__('admin.modules.col_action')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($modules as $m): $active = $m['status'] === 'active'; ?>
        <tr>
            <td><code><?= h($m['module_key']) ?></code>
                <?php if (($m['signature_status'] ?? 'valid') === 'revoked'): ?>
                    <span class="badge text-bg-danger" title="<?= h(__('admin.modules.signature_revoked_title')) ?>"><?= h(__('admin.modules.signature_revoked')) ?></span>
                <?php endif; ?></td>
            <td><?= h($m['name']) ?></td>
            <td><?= h($m['version']) ?></td>
            <td><?= h($m['type']) ?></td>
            <td><?= filter_var($m['requires_license'], FILTER_VALIDATE_BOOLEAN) ? '<span class="badge text-bg-info">' . h(__('admin.modules.license_required')) . '</span>' : '–' ?></td>
            <td><span class="badge text-bg-<?= $badge[$m['status']] ?? 'secondary' ?>"><?= h(__('admin.module.status_' . $m['status'])) ?></span></td>
            <td class="text-end">
                <div class="d-inline-flex gap-1">
                <?php if ($active): ?>
                    <?= $this->Form->postLink(__('admin.modules.btn_deactivate'), ['action' => 'deactivate', $m['module_key']], ['class' => 'btn btn-warning btn-sm']) ?>
                <?php else: ?>
                    <?= $this->Form->postLink(__('admin.modules.btn_activate'), ['action' => 'activate', $m['module_key']], ['class' => 'btn btn-success btn-sm']) ?>
                    <?= $this->UiKit->confirmPost(__('admin.modules.btn_delete'), ['action' => 'delete', $m['module_key']], __('admin.modules.confirm_delete', $m['module_key']), ['class' => 'btn btn-outline-danger btn-sm']) ?>
                <?php endif; ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($modules === []): ?><tr><td colspan="7" class="text-muted"><?= h(__('admin.modules.empty')) ?></td></tr><?php endif; ?>
    </tbody>
</table>

<h2 class="h5 mt-4"><?= h(__('admin.modules.deps_heading')) ?></h2>
<?php if ($deps === []): ?>
    <p class="text-muted"><?= h(__('admin.modules.deps_empty')) ?></p>
<?php else: ?>
    <ul class="list-group">
    <?php foreach ($deps as $d): ?>
        <li class="list-group-item">
            <code><?= h($d['module']) ?></code> &rarr; <code><?= h($d['requires']) ?></code>
            <?php if (!empty($d['required_version'])): ?><span class="text-muted small">(<?= h($d['required_version']) ?>)</span><?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
