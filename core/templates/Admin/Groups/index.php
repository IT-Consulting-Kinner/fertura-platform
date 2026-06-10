<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $groups
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('admin.groups.title')) ?></h1>
    <?= $this->Html->link(__('admin.groups.new'), ['action' => 'add'], ['class' => 'btn btn-primary btn-sm']) ?>
</div>
<table class="table table-hover align-middle">
    <thead><tr><th scope="col"><?= h(__('admin.groups.col_name')) ?></th><th scope="col"><?= h(__('admin.groups.col_description')) ?></th><th scope="col"><?= h(__('admin.groups.col_members')) ?></th><th scope="col"><?= h(__('admin.groups.col_status')) ?></th><th scope="col"></th></tr></thead>
    <tbody>
    <?php foreach ($groups as $g): ?>
        <tr>
            <td><?= h($g['name']) ?></td>
            <td class="text-muted"><?= h($g['description']) ?: '–' ?></td>
            <td><?= (int)$g['member_count'] ?></td>
            <td><?php $a = filter_var($g['active'], FILTER_VALIDATE_BOOLEAN); ?>
                <span class="badge text-bg-<?= $a ? 'success' : 'secondary' ?>"><?= h($a ? __('admin.groups.active') : __('admin.groups.inactive')) ?></span></td>
            <td class="text-end"><?= $this->Html->link(__('admin.groups.manage'), ['action' => 'view', $g['id']], ['class' => 'btn btn-outline-secondary btn-sm']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($groups === []): ?><tr><td colspan="5" class="text-muted"><?= h(__('admin.groups.empty')) ?></td></tr><?php endif; ?>
    </tbody>
</table>
