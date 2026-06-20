<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $contracts
 * @var array<int, array<string, mixed>> $registrations
 * @var array<int, array<string, mixed>> $bindings
 */
$typeBadge = ['resolver' => 'primary', 'collector' => 'info', 'event' => 'warning', 'service' => 'success'];
?>
<h1 class="h3 mb-3"><?= h(__('admin.registry.title')) ?></h1>

<h2 class="h5"><?= h(__('admin.registry.contracts_heading')) ?></h2>
<table class="table table-sm table-hover align-middle">
    <thead><tr><th scope="col"><?= h(__('admin.registry.col_name')) ?></th><th scope="col"><?= h(__('admin.registry.col_type')) ?></th><th scope="col"><?= h(__('admin.registry.col_version')) ?></th><th scope="col"><?= h(__('admin.registry.col_owner_module')) ?></th><th scope="col"><?= h(__('admin.registry.col_multi')) ?></th><th scope="col"><?= h(__('admin.registry.col_registrations')) ?></th><th scope="col"><?= h(__('admin.registry.col_status')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($contracts as $c): ?>
        <tr>
            <td><code><?= h($c['name']) ?></code></td>
            <td><span class="badge text-bg-<?= $typeBadge[$c['contract_type']] ?? 'secondary' ?>"><?= h($c['contract_type']) ?></span></td>
            <td><?= h($c['version']) ?></td>
            <td><?= h($c['owner_module_key']) ?></td>
            <td><?= filter_var($c['multi_use'], FILTER_VALIDATE_BOOLEAN) ? h(__('admin.registry.yes')) : h(__('admin.registry.no')) ?></td>
            <td><?= (int)$c['reg_count'] ?></td>
            <td><?= filter_var($c['active'], FILTER_VALIDATE_BOOLEAN) ? '<span class="badge text-bg-success">' . h(__('admin.registry.status_active')) . '</span>' : '<span class="badge text-bg-secondary">' . h(__('admin.registry.status_inactive')) . '</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($contracts === []): ?><tr><td colspan="7" class="text-muted"><?= h(__('admin.registry.contracts_empty')) ?></td></tr><?php endif; ?>
    </tbody>
</table>

<h2 class="h5 mt-4"><?= h(__('admin.registry.registrations_heading')) ?></h2>
<table class="table table-sm table-hover align-middle">
    <thead><tr><th scope="col"><?= h(__('admin.registry.col_contract')) ?></th><th scope="col"><?= h(__('admin.registry.col_module')) ?></th><th scope="col"><?= h(__('admin.registry.col_type')) ?></th><th scope="col"><?= h(__('admin.registry.col_implementation')) ?></th><th scope="col"><?= h(__('admin.registry.col_priority')) ?></th><th scope="col"><?= h(__('admin.registry.col_status')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($registrations as $r): ?>
        <tr>
            <td><code><?= h($r['contract']) ?></code></td>
            <td><?= h($r['module_key']) ?></td>
            <td><?= h($r['registration_type']) ?></td>
            <td class="small text-muted"><?= h($r['implementation_class']) ?: '–' ?></td>
            <td><?= (int)$r['priority'] ?></td>
            <td><?= $this->UiKit->activeBadge($r['active'], __('admin.registry.status_active'), __('admin.registry.status_inactive')) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($registrations === []): ?><tr><td colspan="6" class="text-muted"><?= h(__('admin.registry.registrations_empty')) ?></td></tr><?php endif; ?>
    </tbody>
</table>

<h2 class="h5 mt-4"><?= h(__('admin.registry.bindings_heading')) ?></h2>
<table class="table table-sm table-hover align-middle">
    <thead><tr><th scope="col"><?= h(__('admin.registry.col_module')) ?></th><th scope="col"><?= h(__('admin.registry.col_contract')) ?></th><th scope="col"><?= h(__('admin.registry.col_status')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($bindings as $b): ?>
        <tr><td><?= h($b['module_key']) ?></td><td><code><?= h($b['contract']) ?></code></td>
            <td><span class="badge text-bg-<?= $b['status'] === 'active' ? 'success' : 'secondary' ?>"><?= h($b['status']) ?></span></td></tr>
    <?php endforeach; ?>
    <?php if ($bindings === []): ?><tr><td colspan="3" class="text-muted"><?= h(__('admin.registry.bindings_empty')) ?></td></tr><?php endif; ?>
    </tbody>
</table>
