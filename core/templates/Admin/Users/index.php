<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $users
 */
$badge = ['active' => 'success', 'invited' => 'info', 'disabled' => 'secondary', 'anonymized' => 'dark'];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('admin.users.title')) ?></h1>
    <?= $this->Html->link(__('admin.users.new'), ['action' => 'add'], ['class' => 'btn btn-primary btn-sm']) ?>
</div>
<table class="table table-hover align-middle">
    <thead><tr><th scope="col"><?= h(__('admin.users.col_username')) ?></th><th scope="col"><?= h(__('admin.users.col_name')) ?></th><th scope="col"><?= h(__('admin.users.col_email')) ?></th><th scope="col"><?= h(__('admin.users.col_status')) ?></th><th scope="col"></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><?= h($u['username']) ?></td>
            <td><?= h(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?></td>
            <td><?= h($u['email']) ?></td>
            <td><span class="badge text-bg-<?= $badge[$u['status']] ?? 'secondary' ?>"><?= h($u['status']) ?></span></td>
            <td class="text-end"><?= $this->Html->link(__('admin.users.details'), ['action' => 'view', $u['id']], ['class' => 'btn btn-outline-secondary btn-sm']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($users === []): ?>
        <tr><td colspan="5" class="text-muted"><?= h(__('admin.users.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
